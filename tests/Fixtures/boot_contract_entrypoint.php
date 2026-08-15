<?php

declare(strict_types=1);

/**
 * Stands in for an application entry point guarded by an EnvironmentContract.
 *
 * Driven as a subprocess by EnvironmentContractTest so the refusal path can be
 * asserted for real, exit code included, without terminating the test runner.
 *
 * Prints BOOTED plus the canonical MODE when the contract is satisfied.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Zephyrus\Core\Config\EnvironmentContract;

$contract = EnvironmentContract::create();

if (getenv('CONTRACT_EMPTY') !== '1') {
    $contract = $contract
        ->requireOneOf('APP_ENV', ['dev', 'staging', 'production'])
        ->requireOneOf('MODE', ['WEB', 'API'], canonicalise: true)
        ->requireBase64Bytes('ENCRYPTION_KEY', 32);
}

$contract->enforce();

echo 'BOOTED MODE=' . (string) getenv('MODE') . PHP_EOL;
