<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Driver;

/**
 * THE DRIVER LACKS A CAPABILITY the journal needs — naming the capability,
 * why it is needed, and the interface the driver should implement.
 */
final class MissingCapability extends \LogicException
{
    /** @param class-string $interface */
    public function __construct(
        public readonly string $capability,
        string $why,
        public readonly string $interface,
    ) {
        parent::__construct(\sprintf(
            'the driver lacks the %s capability, needed by %s: it does not implement %s',
            $capability,
            $why,
            $interface,
        ));
    }
}
