<?php

declare(strict_types=1);

namespace Tomrf\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

class Logger extends AbstractLogger implements LoggerInterface
{
    /**
     * Supported log levels. Logging with a level not specified in LOG_LEVELS
     * will throw InvalidArgumentException per PSR-3 section 1.1.
     */
    private const LOG_LEVELS = [
        'emergency',
        'alert',
        'critical',
        'error',
        'warning',
        'notice',
        'info',
        'debug',
    ];

    /**
     * Optional log message formatter.
     *
     * @var null|callable
     */
    private $formatter;

    /**
     * Optional log message outputter.
     *
     * @var null|callable
     */
    private $outputter;

    /**
     * Log file stream resource.
     *
     * @var resource
     */
    private $stream;

    public function __construct(
        mixed $stream = null,
    ) {
        if (null === $stream) {
            return;
        }

        $this->setStream($stream);
    }

    public function setStream(mixed $stream): void
    {
        // assert that $stream is a resource
        if (!\is_resource($stream)) {
            throw new InvalidArgumentException(
                'Argument 1 passed to '.__METHOD__.' must be of type resource, '.
                \gettype($stream).' given'
            );
        }

        // assert that $stream is a stream resource
        if ('stream' !== get_resource_type($stream)) {
            throw new InvalidArgumentException(
                'Argument 1 passed to '.__METHOD__.' must be a file stream, '.
                get_resource_type($stream).' given'
            );
        }

        $this->stream = $stream;
    }

    /**
     * Set formatter callable.
     */
    public function setFormatter(callable|null $formatter): void
    {
        $this->formatter = $formatter;
    }

    /**
     * Set outputter callable.
     */
    public function setOutputter(callable|null $outputter): void
    {
        $this->outputter = $outputter;
    }

    /**
     * Log message.
     *
     * @param mixed        $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!\in_array($level, self::LOG_LEVELS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported log level "%s"',
                $this->getStringValue($level)
            ));
        }

        if (method_exists($message, '__toString')) {
            $message = (string) $message;
        }

        foreach ($context as $key => $value) {
            $message = str_replace(
                sprintf('{%s}', (string) $key),
                sprintf('%s', $this->getStringValue($value)),
                (string) $message
            );
        }

        $output = sprintf("[%s] (%s) %s\n", date('c'), $level, $message);

        if (null !== $this->formatter) {
            $output = $this->getStringValue(
                \call_user_func($this->formatter, $level, $message)
            );
        }

        if (null !== $this->outputter) {
            \call_user_func($this->outputter, $this->stream, $output);

            return;
        }

        $this->writeToStream($output);
    }

    /**
     * Close the log stream for writing.
     */
    public function closeStream(): void
    {
        if (null !== $this->stream) {
            fclose($this->stream);
        }
    }

    /**
     * Truncate the log stream.
     *
     * @param int<0, max> $size
     */
    public function truncateStream(int $size = 0): void
    {
        if (null !== $this->stream) {
            ftruncate($this->stream, $size);
        }
    }

    /**
     * Write to stream if it is open.
     *
     * Returns number of bytes written, or -1 on error.
     *
     * @throws RuntimeException
     */
    private function writeToStream(string $data): int
    {
        if (null === $this->stream) {
            return 0;
        }

        $bytes = fwrite($this->stream, $data);

        if (false === $bytes) {
            return -1;
        }

        return $bytes;
    }

    /**
     * Return a printable string representation of any variable type.
     */
    private function getStringValue(mixed $var): string
    {
        if (\is_string($var) || \is_int($var) || \is_float($var)) {
            return (string) $var;
        }

        if (\is_object($var) && method_exists($var, '__toString')) {
            return (string) $var;
        }

        if (\is_bool($var)) {
            if (true === $var) {
                return '<bool:true>';
            }

            return '<bool:false>';
        }

        if (\is_resource($var)) {
            return sprintf('<resource:%s>', get_resource_type($var));
        }

        return sprintf('<%s>', \gettype($var));
    }
}
