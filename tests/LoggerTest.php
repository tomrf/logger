<?php

declare(strict_types=1);

namespace Tomrf\Logger;

use DateTime;

/**
 * @internal
 * @coversNothing
 */
final class LoggerTest extends \PHPUnit\Framework\TestCase
{
    private const LOGFILE = '/tmp/loggertest.phpunit.log';
    private static Logger $logger;

    private $buffer;

    public static function setUpBeforeClass(): void
    {
        self::$logger = new \Tomrf\Logger\Logger(self::LOGFILE, 0600);
        self::$logger->setFormatter(
            fn ($level, $message) => $level.':'.$message.PHP_EOL
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$logger->closeStream();
        unlink(self::LOGFILE);
    }

    public function testLogfileExistsAndIsWriteable(): void
    {
        static::assertFileExists(self::LOGFILE);
        static::assertFileIsWritable(self::LOGFILE);
    }

    public function testLogger(): void
    {
        foreach ([
            'emergency',
            'alert',
            'critical',
            'error',
            'warning',
            'notice',
            'info',
            'debug',
        ] as $level) {
            $this->logger()->{$level}('string{rep1}test/{rep2}/log', [
                'rep1' => 'AA',
                'rep2' => $level,
            ]);
            $expect = sprintf(
                '%s%s:stringAAtest/%s/log'.PHP_EOL,
                $expect ?? '',
                $level,
                $level,
            );
        }

        static::assertStringEqualsFile(self::LOGFILE, $expect);
    }

    public function testLogWithInvalidLevelThrowsException(): void
    {
        $this->expectException(\Psr\Log\InvalidArgumentException::class);
        $this->logger()->log('illegal', 'illegal log level');
    }

    public function testTruncateLogStream(): void
    {
        $this->logger()->truncateStream(0);
        static::assertStringEqualsFile(self::LOGFILE, '');
    }

    public function testLogWithCustomFormatter(): void
    {
        $this->logger()->setFormatter(
            fn ($level, $message) => sprintf('<<< %s >>> %s <<<', $level, $message)
        );

        $this->logger()->log('alert', PHP_VERSION);

        static::assertStringEqualsFile(self::LOGFILE, sprintf(
            '<<< %s >>> %s <<<',
            'alert',
            PHP_VERSION
        ));

        // reset formatter
        $this->logger()->setFormatter(
            fn ($level, $message) => sprintf('%s:%s', $level, $message)
        );
    }

    public function testLogWithCustomOutputter(): void
    {
        $this->logger()->setOutputter(function ($stream, $output): void {
            $this->buffer = $output;
        });
        $this->logger()->notice('testing custom outputter');
        static::assertSame('notice:testing custom outputter', $this->buffer);

        // reset outputter
        $this->logger()->setOutputter(null);
    }

    public function testLogWithPrintableScalarTypesInContext(): void
    {
        $this->logger()->truncateStream();

        $this->logger()->debug('{a}{b}{c}', [
            'a' => 'string',
            'b' => 123456789,
            'c' => 123.12345,
        ]);

        static::assertStringEqualsFile(
            self::LOGFILE,
            'debug:string123456789123.12345'
        );
    }

    public function testLogWithNonPrintableTypesInContext(): void
    {
        $this->logger()->truncateStream();

        $fp = fopen(self::LOGFILE, 'r');

        $this->logger()->alert('{a}{b}{c}{d}{e}{f}{g}', [
            'a' => true,
            'b' => new DateTime(),
            'c' => $fp,
            'd' => ['array' => 1, 'test' => 2],
            'e' => fn () => 'closure',
            'f' => false,
            'g' => null,
        ]);

        fclose($fp);

        static::assertStringEqualsFile(
            self::LOGFILE,
            'alert:<bool:true><object><resource:stream><array><object><bool:false><NULL>'
        );
    }

    private function logger(): Logger
    {
        return self::$logger;
    }
}
