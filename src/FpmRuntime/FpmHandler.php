<?php declare(strict_types=1);

namespace Bref\FpmRuntime;

use Bref\Context\Context;
use Bref\Event\Http\HttpHandler;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\Http\HttpResponse;
use Bref\FpmRuntime\FastCgi\FastCgiCommunicationFailed;
use Bref\FpmRuntime\FastCgi\FastCgiRequest;
use Bref\FpmRuntime\FastCgi\Timeout;
use Exception;
use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Exceptions\TimedoutException;
use hollodotme\FastCGI\Interfaces\ProvidesRequestData;
use hollodotme\FastCGI\Interfaces\ProvidesResponseData;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;
use RuntimeException;
use Throwable;

/**
 * Handles HTTP events coming from API Gateway/ALB by proxying them to PHP-FPM via FastCGI.
 *
 * Usage example:
 *
 *     $event = [get the Lambda event];
 *     $phpFpm = new FpmHandler('index.php');
 *     $phpFpm->start();
 *     $lambdaResponse = $phpFpm->handle($event);
 *     $phpFpm->stop();
 *     [send the $lambdaResponse];
 *
 * @internal
 */
final class FpmHandler extends HttpHandler
{
    private const SOCKET = '/tmp/.bref/php-fpm.sock';
    private const PID_FILE = '/tmp/.bref/php-fpm.pid';
    private const CONFIG = '/opt/bref/etc/php-fpm.conf';
    /**
     * We define this constant instead of using the PHP one because that avoids
     * depending on the pcntl extension.
     */
    private const SIGTERM = 15;

    private ?Client $client;
    private UnixDomainSocket $connection;
    private string $handler;
    private string $configFile;
    /** @var resource|null */
    private $fpm;

    public function __construct(string $handler, string $configFile = self::CONFIG)
    {
        $this->handler = $handler;
        $this->configFile = $configFile;
    }

    /**
     * Start the PHP-FPM process.
     *
     * @throws Exception
     */
    public function start(): void
    {
        // In case Lambda stopped our process (e.g. because of a timeout) we need to make sure PHP-FPM has stopped
        // as well and restart it
        if ($this->isReady()) {
            $this->killExistingFpm();
        }

        if (! is_dir(dirname(self::SOCKET))) {
            mkdir(dirname(self::SOCKET));
        }

        /**
         * --nodaemonize: we want to keep control of the process
         * --force-stderr: force logs to be sent to stderr, which will allow us to send them to CloudWatch
         */
        $resource = @proc_open(['php-fpm', '--nodaemonize', '--force-stderr', '--fpm-config', $this->configFile], [], $pipes);

        if (! is_resource($resource)) {
            throw new RuntimeException('PHP-FPM failed to start');
        }
        $this->fpm = $resource;

        $this->client = new Client;
        $this->connection = new UnixDomainSocket(self::SOCKET, 1000, 900000);

        $this->waitUntilReady();
    }

    /**
     * @throws Exception
     */
    public function stop(): void
    {
        if ($this->isFpmRunning()) {
            // Give it less than a second to stop (500ms should be plenty enough time)
            // this is for the case where the script timed out: we reserve 1 second before the end
            // of the Lambda timeout, so we must kill everything and restart FPM in 1 second.
            $this->stopFpm(0.5);
            if ($this->isReady()) {
                throw new Exception('PHP-FPM cannot be stopped');
            }
        }
    }

    /**
     * @throws Exception
     */
    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Proxy the API Gateway event to PHP-FPM and return its response.
     *
     * @throws FastCgiCommunicationFailed
     * @throws Timeout
     * @throws Exception
     */
    public function handleRequest(HttpRequestEvent $event, Context $context): HttpResponse
    {
        $request = $this->eventToFastCgiRequest($event, $context);

        // The script will timeout 1 second before the remaining time
        // to allow some time for Bref/PHP-FPM to recover and cleanup
        $margin = 1000;
        $timeoutDelayInMs = max(1000, $context->getRemainingTimeInMillis() - $margin);

        try {
            $socketId = $this->client->sendAsyncRequest($this->connection, $request);

            $response = $this->client->readResponse($socketId, $timeoutDelayInMs);
        } catch (TimedoutException) {
            $invocationId = $context->getAwsRequestId();
            echo "$invocationId The PHP script timed out. Bref will now restart PHP-FPM to start from a clean slate and flush the PHP logs.\nTimeouts can happen for example when trying to connect to a remote API or database, if this happens continuously check for those.\nIf you are using a RDS database, read this: https://bref.sh/docs/environment/database.html#accessing-the-internet\n";

            /**
             * Restart FPM so that the blocked script is 100% terminated and that its logs are flushed to stderr.
             *
             * - "why restart FPM?": if we don't, the previous request continues to execute on the next request
             * - "why not send a SIGUSR2 signal to FPM?": that was a promising approach because SIGUSR2
             *   causes FPM to cleanly stop the FPM worker that is stuck in a timeout/waiting state.
             *   It also causes all worker logs buffered by FPM to be written to stderr (great!).
             *   This takes a bit of time (a few ms), but it's faster than rebooting FPM entirely.
             *   However, the downside is that it doesn't "kill" the previous request execution:
             *   it merely stops the execution of the line of code that is waiting (e.g. "sleep()",
             *   "file_get_contents()", ...) and continues to the next line. That's super weird!
             *   So SIGUSR2 isn't a great solution in the end.
             */
            $this->stop();
            $this->start();

            // Throw an exception so that:
            // - this is reported as a Lambda execution error ("error rate" metrics are accurate)
            // - the CloudWatch logs correctly reflect that an execution error occurred
            // - the 500 response is the same as if an exception happened in Bref
            throw new Timeout($timeoutDelayInMs, $context->getAwsRequestId());
        } catch (Throwable $e) {
            printf(
                "Error communicating with PHP-FPM to read the HTTP response. Bref will restart PHP-FPM now. Original exception message: %s %s\n",
                get_class($e),
                $e->getMessage()
            );

            // Restart PHP-FPM: in some cases PHP-FPM is borked, that's the only way we can recover
            $this->stop();
            $this->start();

            throw new FastCgiCommunicationFailed;
        }

        $responseHeaders = $this->getResponseHeaders($response);

        // Extract the status code
        if (isset($responseHeaders['status'])) {
            $status = (int) (is_array($responseHeaders['status']) ? $responseHeaders['status'][0] : $responseHeaders['status']);
            unset($responseHeaders['status']);
        }

        $this->ensureStillRunning();

        return new HttpResponse($response->getBody(), $responseHeaders, $status ?? 200);
    }

    /**
     * @throws Exception If the PHP-FPM process is not running anymore.
     */
    private function ensureStillRunning(): void
    {
        if (! $this->isFpmRunning()) {
            throw new Exception('PHP-FPM has stopped for an unknown reason');
        }
    }

    /**
     * @throws Exception
     */
    private function waitUntilReady(): void
    {
        $wait = 5000; // 5ms
        $timeout = 5000000; // 5 secs
        $elapsed = 0;

        while (! $this->isReady()) {
            usleep($wait);
            $elapsed += $wait;

            if ($elapsed > $timeout) {
                throw new Exception('Timeout while waiting for PHP-FPM socket at ' . self::SOCKET);
            }

            // If the process has crashed we can stop immediately
            if (! $this->isFpmRunning()) {
                // The output of FPM is in the stderr of the Lambda process
                throw new Exception('PHP-FPM failed to start');
            }
        }
    }

    private function isReady(): bool
    {
        clearstatcache(false, self::SOCKET);

        return file_exists(self::SOCKET);
    }

    private function eventToFastCgiRequest(HttpRequestEvent $event, Context $context): ProvidesRequestData
    {
        $request = new FastCgiRequest($event->getMethod(), $this->handler, $event->getBody());
        $request->setRequestUri($event->getUri());
        $request->setRemoteAddress('127.0.0.1');
        $request->setRemotePort($event->getRemotePort());
        $request->setServerAddress('127.0.0.1');
        $request->setServerName($event->getServerName());
        $request->setServerProtocol($event->getProtocol());
        $request->setServerPort($event->getServerPort());
        $request->setCustomVar('PATH_INFO', $event->getPath());
        $request->setCustomVar('QUERY_STRING', $event->getQueryString());
        $request->setCustomVar('LAMBDA_INVOCATION_CONTEXT', json_encode($context, JSON_THROW_ON_ERROR));
        $request->setCustomVar('LAMBDA_REQUEST_CONTEXT', json_encode($event->getRequestContext(), JSON_THROW_ON_ERROR));

        $contentType = $event->getContentType();
        if ($contentType) {
            $request->setContentType($contentType);
        }
        foreach ($event->getHeaders() as $header => $values) {
            foreach ($values as $value) {
                $key = 'HTTP_' . strtoupper(str_replace('-', '_', (string) $header));
                $request->setCustomVar($key, $value);
            }
        }

        return $request;
    }

    /**
     * This method makes sure to kill any existing PHP-FPM process.
     *
     * @throws Exception
     */
    private function killExistingFpm(): void
    {
        // Never seen this happen but just in case
        if (! file_exists(self::PID_FILE)) {
            unlink(self::SOCKET);
            return;
        }

        $pid = (int) file_get_contents(self::PID_FILE);

        // Never seen this happen but just in case
        if ($pid <= 0) {
            echo "PHP-FPM's PID file contained an invalid PID, assuming PHP-FPM isn't running.\n";
            unlink(self::SOCKET);
            unlink(self::PID_FILE);
            return;
        }

        // Check if the process is running
        if (posix_getpgid($pid) === false) {
            // PHP-FPM is not running anymore, we can cleanup
            unlink(self::SOCKET);
            unlink(self::PID_FILE);
            return;
        }

        // The PID could be reused by our new process: let's not kill ourselves
        // See https://github.com/brefphp/bref/pull/645
        if ($pid === posix_getpid()) {
            unlink(self::SOCKET);
            unlink(self::PID_FILE);
            return;
        }

        echo "PHP-FPM seems to be running already. This might be because Lambda stopped the bootstrap process but didn't leave us an opportunity to stop PHP-FPM (did Lambda timeout?). Stopping PHP-FPM now to restart from a blank slate.\n";

        // The previous PHP-FPM process is running, let's try to kill it properly
        $result = posix_kill($pid, self::SIGTERM);
        if ($result === false) {
            echo "PHP-FPM's PID file contained a PID that doesn't exist, assuming PHP-FPM isn't running.\n";
            unlink(self::SOCKET);
            unlink(self::PID_FILE);
            return;
        }
        $this->waitUntilStopped($pid);
        unlink(self::SOCKET);
        unlink(self::PID_FILE);
    }

    /**
     * Wait until PHP-FPM has stopped.
     *
     * @throws Exception
     */
    private function waitUntilStopped(int $pid): void
    {
        $wait = 5000; // 5ms
        $timeout = 1000000; // 1 sec
        $elapsed = 0;
        while (posix_getpgid($pid) !== false) {
            usleep($wait);
            $elapsed += $wait;
            if ($elapsed > $timeout) {
                throw new Exception('Timeout while waiting for PHP-FPM to stop');
            }
        }
    }

    /**
     * Return an array of the response headers.
     */
    private function getResponseHeaders(ProvidesResponseData $response): array
    {
        return array_change_key_case($response->getHeaders(), CASE_LOWER);
    }

    public function stopFpm(float $timeout): void
    {
        if (! $this->fpm) {
            return;
        }

        $timeoutMicro = microtime(true) + $timeout;
        if ($this->isFpmRunning()) {
            $pid = proc_get_status($this->fpm)['pid'];
            // SIGTERM
            @posix_kill($pid, 15);
            do {
                usleep(1000);
                // @phpstan-ignore-next-line
            } while ($this->isFpmRunning() && microtime(true) < $timeoutMicro);

            // @phpstan-ignore-next-line
            if ($this->isFpmRunning()) {
                // SIGKILL
                @posix_kill($pid, 9);
                usleep(1000);
            }
        }

        proc_close($this->fpm);
        $this->fpm = null;
    }

    private function isFpmRunning(): bool
    {
        return $this->fpm && proc_get_status($this->fpm)['running'];
    }
}
