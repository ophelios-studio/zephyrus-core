<?php

declare(strict_types=1);

namespace Zephyrus\Data;

use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Raised when a DatabaseActor is declared or used wrongly, or finds an actor already published. Its messages
 * name keys and settings, never a published value.
 */
final class DatabaseActorException extends ZephyrusRuntimeException
{
    private const string NAME_RULE = 'use 1 to 63 lowercase letters, digits and underscores, not starting with a digit.';

    public static function postgresRequired(): self
    {
        return new self('DatabaseActor needs a PostgreSQL connection: set_config() and row level security exist only there.');
    }

    public static function invalidPrefix(string $prefix): self
    {
        return new self(sprintf(
            'The actor prefix %s is not a valid setting namespace: %s',
            MessageValue::quote($prefix),
            self::NAME_RULE,
        ));
    }

    public static function invalidKey(mixed $key): self
    {
        return new self(sprintf(
            'The actor key %s is not a valid setting name: %s',
            MessageValue::describe($key),
            self::NAME_RULE,
        ));
    }

    public static function noKeys(): self
    {
        return new self('A DatabaseActor needs at least one key.');
    }

    public static function duplicateKey(string $key): self
    {
        return new self(sprintf('The actor key %s is declared twice.', MessageValue::quote($key)));
    }

    public static function noValues(): self
    {
        return new self('An actor publication needs at least one value.');
    }

    /**
     * @param list<string> $declared
     */
    public static function undeclaredKey(int|string $key, array $declared): self
    {
        return new self(sprintf(
            'The actor values key %s names no declared key (declared: %s).',
            MessageValue::describe($key),
            MessageValue::quoteList($declared),
        ));
    }

    public static function valueNotString(string $key, string $given): self
    {
        return new self(sprintf(
            'The actor value for %s must be a string, %s given: cast it first, such as (string) $userId.',
            MessageValue::quote($key),
            $given,
        ));
    }

    public static function emptyValue(string $key): self
    {
        return new self(sprintf(
            'The actor value for %s must be a non-empty string, an empty string given: an empty value reads as no '
            . 'actor, so leave the key out instead.',
            MessageValue::quote($key),
        ));
    }

    public static function transactionAlreadyOpen(): self
    {
        return new self(
            'DatabaseActor::actAs() opens its own transaction and refuses one already open, where the actor would '
            . 'outlive the callback until that transaction ends: call actAs() outside any transaction, or publish() '
            . 'inside the open one.',
        );
    }

    public static function transactionRequired(): self
    {
        return new self(
            'DatabaseActor::publish() needs an open transaction: outside one, PostgreSQL drops the actor before the '
            . 'next statement. Use actAs(), or call publish() inside Database::transaction().',
        );
    }

    /**
     * @param list<string> $settings
     */
    public static function alreadyPublished(array $settings): self
    {
        $set = count($settings) === 1
            ? sprintf('%s is set', MessageValue::quoteList($settings))
            : sprintf('one of %s is set', MessageValue::quoteList($settings));

        return new self(sprintf(
            'An actor is already published on this connection (%s): an actor changes only by ending its '
            . 'transaction, and a value set for the whole session is refused.',
            $set,
        ));
    }
}
