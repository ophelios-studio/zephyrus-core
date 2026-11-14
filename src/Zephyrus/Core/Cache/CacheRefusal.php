<?php

declare(strict_types=1);

namespace Zephyrus\Core\Cache;

/**
 * The reason a cache path is refused, read or written. Never leaves the cache classes.
 *
 * @internal
 */
final class CacheRefusal extends \RuntimeException
{
}
