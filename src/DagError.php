<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/** A graph that does not hold together, or a node nobody declared. */
final class DagError extends \InvalidArgumentException {}
