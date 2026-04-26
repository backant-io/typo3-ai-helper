<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for issue #25.
 *
 * TYPO3 v13.4 ships Doctrine DBAL 4. DBAL 4 dropped support for the legacy
 * integer-form constants (\PDO::PARAM_INT === 1) on
 * QueryBuilder::createNamedParameter() — the second arg must now be one of:
 *   - Doctrine\DBAL\ParameterType
 *   - Doctrine\DBAL\Types\Type
 *   - Doctrine\DBAL\ArrayParameterType
 *   - string
 *
 * Passing \PDO::PARAM_INT raises TypeError at runtime. This was a demo-blocker
 * caught only on a real v13.4 install — PHPUnit tests with mocked QueryBuilder
 * never executed the strict signature.
 *
 * This test is a static-analysis-style guard: it greps the production source
 * tree for any `\PDO::PARAM_*` reference and fails the suite if any is found.
 * Cheaper than a full functional test against typo3/testing-framework, and
 * locks in the fix as long as nobody disables the test.
 */
final class DbalParameterTypeRegressionTest extends TestCase
{
    public function testNoLegacyPdoParamConstantsInProductionCode(): void
    {
        $classesDir = realpath(__DIR__ . '/../../Classes');
        self::assertNotFalse($classesDir, 'Classes/ directory must exist');

        $offenders = $this->scanForPdoParam($classesDir);

        self::assertSame(
            [],
            $offenders,
            'Found legacy \\PDO::PARAM_* references in production code. '
            . 'TYPO3 v13.4 / DBAL 4 require Doctrine\\DBAL\\ParameterType. See issue #25.' . PHP_EOL
            . 'Offending lines:' . PHP_EOL . implode(PHP_EOL, $offenders),
        );
    }

    public function testEveryProductionFileUsingNamedParameterImportsParameterType(): void
    {
        $classesDir = realpath(__DIR__ . '/../../Classes');
        self::assertNotFalse($classesDir);

        $missing = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($classesDir));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string)file_get_contents($file->getPathname());
            if (!str_contains($contents, 'createNamedParameter')) {
                continue;
            }
            // If the file uses createNamedParameter with the typed second arg,
            // it must import Doctrine\DBAL\ParameterType.
            if (str_contains($contents, 'ParameterType::')
                && !str_contains($contents, 'use Doctrine\\DBAL\\ParameterType;')
            ) {
                $missing[] = str_replace($classesDir . '/', '', $file->getPathname());
            }
        }

        self::assertSame(
            [],
            $missing,
            'Files use ParameterType::* but missing the use Doctrine\\DBAL\\ParameterType; import.',
        );
    }

    /**
     * @return list<string>
     */
    private function scanForPdoParam(string $dir): array
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace($dir . '/', '', $file->getPathname());
            $lines = file($file->getPathname()) ?: [];
            foreach ($lines as $lineNo => $line) {
                if (str_contains($line, '\\PDO::PARAM') || preg_match('/\bPDO::PARAM/', $line)) {
                    $offenders[] = sprintf('  %s:%d  %s', $relative, $lineNo + 1, trim($line));
                }
            }
        }
        return $offenders;
    }
}
