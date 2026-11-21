<?php
/**
 * Run Strauss from its own install in tools/strauss/vendor/.
 *
 * Strauss's bin script would load the project's vendor/autoload.php (it checks
 * getcwd()), where delete_vendor_packages has pruned packages Strauss itself
 * needs, so load only this directory's autoloader.
 *
 * @package PulseQuestion
 */

require __DIR__ . '/vendor/autoload.php';

( new BrianHenryIE\Strauss\Console\Application( (string) Composer\InstalledVersions::getPrettyVersion( 'brianhenryie/strauss' ) ) )->run();
