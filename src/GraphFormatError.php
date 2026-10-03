<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/** A graph document that cannot be read — the message names the path to what is wrong. */
final class GraphFormatError extends \InvalidArgumentException {}
