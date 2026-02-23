<?php

declare(strict_types=1);

namespace Laika\Model\Drivers;

interface DriverInterface
{
    /**
     * Build the PDO DSN string from config.
     */
    public function buildDsn(array $config): string;

    /**
     * Return any PDO options specific to this driver.
     */
    public function getOptions(array $config): array;

    /**
     * Return the driver name (lowercase canonical, e.g. "mysql").
     */
    public function getName(): string;
}
