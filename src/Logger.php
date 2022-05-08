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
     * Log file path/filename.
     */
    private ?string $filename = null;

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
        ?string $filename = null,
        int $permissions = 0600,
    ) {
        if (null !== $filename) {
            $this->setFilename($filename, $permissions);
        }
    }

    /**
     * Set log file filename/path. If the file does not exist it will be
     * created and permissions set via chmod(). Can be set to null to disable
     * logging to file.
     */
    public function setFilename(string|null $filename, int $permissions = 0600): void
    {
        $this->filename = $filename;

        if (null !== $this->filename) {
            $this->createFileIfNotExists($this->filename, $permissions);
        }
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

        if (null !== $this->formatter) {
            $output = $this->getStringValue(
                \call_user_func($this->formatter, $level, $message)
            );
        } else {
            $output = sprintf("[%s] (%s) %s\n", date('c'), $level, $message);
        }

        if (null !== $this->outputter) {
            \call_user_func($this->outputter, $this->stream, $output);
        } elseif (null !== $this->filename) {
            $this->writeToFile($output);
        }
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
     * Create file with correct permissions, if it does not already exist.
     *
     * @throws RuntimeException
     */
    private function createFileIfNotExists(string $filename, int $permissions): void
    {
        if (file_exists($filename)) {
            return;
        }

        if (false === touch($filename)) {
            throw new RuntimeException(sprintf(
                'Unable to create log file "%s": touch() failed',
                $filename
            ));
        }

        if (false === chmod($filename, $permissions)) {
            throw new RuntimeException(sprintf(
                'Unable to set permissions for log file "%s": chmod() failed',
                $filename
            ));
        }
    }

    /**
     * Write to log file.
     *
     * @throws RuntimeException
     */
    private function writeToFile(string $data): void
    {
        if (null === $this->stream && \is_string($this->filename)) {
            if (is_dir($this->filename)) {
                throw new RuntimeException(sprintf(
                    'Could not open log file "%s": is a directory',
                    $this->filename
                ));
            }

            $stream = fopen($this->filename, 'a');

            if (false === $stream) {
                throw new RuntimeException(sprintf(
                    'Could not open log file "%s" for writing: fopen() failed',
                    $this->filename
                ));
            }

            $this->stream = $stream;
        }

        fwrite($this->stream, $data);
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
