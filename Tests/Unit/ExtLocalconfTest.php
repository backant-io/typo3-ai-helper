<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit;

use Kairos\AiEditorialHelper\Form\FieldWizard\GenerateMetaDescriptionWizard;
use Kairos\AiEditorialHelper\Form\FieldWizard\GenerateTeaserWizard;
use Kairos\AiEditorialHelper\Form\FieldWizard\SuggestCategoriesWizard;
use Kairos\AiEditorialHelper\Form\FieldWizard\SuggestSlugWizard;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for issue #11.
 *
 * The FormEngine NodeFactory reads its registry from
 * $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'] at
 * construction time. Registration MUST live in ext_localconf.php (run on
 * every request) and NOT in a TCA override file (cached after first run,
 * causing the "Unknown type" error on subsequent requests).
 *
 * This test loads ext_localconf.php in a controlled state and asserts the
 * registry contains a valid entry for each shipped wizard, with the
 * priority bounds that NodeFactory enforces (0..100).
 *
 * Adding a new wizard? Extend EXPECTED_NODES below to lock it in.
 */
final class ExtLocalconfTest extends TestCase
{
    /**
     * @var array<string, class-string>
     */
    private const EXPECTED_NODES = [
        'aiEditorialHelperGenerateMeta' => GenerateMetaDescriptionWizard::class,
        'aiEditorialHelperSuggestSlug' => SuggestSlugWizard::class,
        'aiEditorialHelperGenerateTeaser' => GenerateTeaserWizard::class,
        'aiEditorialHelperSuggestCategories' => SuggestCategoriesWizard::class,
    ];

    /** @var array<string, mixed>|null */
    private ?array $savedConfVars = null;

    protected function setUp(): void
    {
        $this->savedConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['formEngine' => ['nodeRegistry' => []]]];
        if (!defined('TYPO3')) {
            define('TYPO3', 'test');
        }
    }

    protected function tearDown(): void
    {
        if ($this->savedConfVars !== null) {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->savedConfVars;
        } else {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        }
    }

    public function testEveryShippedWizardIsRegistered(): void
    {
        require __DIR__ . '/../../ext_localconf.php';

        $registry = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'] ?? [];
        $byName = $this->indexByNodeName($registry);

        foreach (self::EXPECTED_NODES as $nodeName => $expectedClass) {
            self::assertArrayHasKey(
                $nodeName,
                $byName,
                sprintf('Wizard %s missing from nodeRegistry — likely registered in a TCA override file (issue #11).', $nodeName),
            );
            self::assertSame($expectedClass, $byName[$nodeName]['class']);
        }
    }

    public function testEveryRegistrationHasRequiredKeysAndValidPriority(): void
    {
        require __DIR__ . '/../../ext_localconf.php';

        $registry = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'] ?? [];
        self::assertNotEmpty($registry, 'No node-registry entries found — extension would be a no-op.');

        foreach ($registry as $key => $entry) {
            self::assertIsArray($entry, "Entry [$key] must be an array");
            self::assertArrayHasKey('nodeName', $entry, "Entry [$key] missing nodeName");
            self::assertArrayHasKey('class', $entry, "Entry [$key] missing class");
            self::assertArrayHasKey('priority', $entry, "Entry [$key] missing priority");
            self::assertIsString($entry['nodeName']);
            self::assertIsString($entry['class']);
            self::assertIsInt($entry['priority']);
            self::assertGreaterThanOrEqual(0, $entry['priority']);
            self::assertLessThanOrEqual(100, $entry['priority']);
            self::assertTrue(
                class_exists($entry['class']),
                "Class {$entry['class']} for nodeName {$entry['nodeName']} does not exist",
            );
        }
    }

    public function testRegistrationKeysAreUnique(): void
    {
        require __DIR__ . '/../../ext_localconf.php';

        $registry = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'] ?? [];
        $keys = array_keys($registry);
        self::assertSame(count($keys), count(array_unique($keys)));
    }

    public function testTcaOverrideDoesNotWriteToNodeRegistry(): void
    {
        // The TCA override file should NEVER mutate $GLOBALS['TYPO3_CONF_VARS'].
        // That was the original #11 bug pattern.
        $tcaOverridePath = __DIR__ . '/../../Configuration/TCA/Overrides/pages.php';
        self::assertFileExists($tcaOverridePath);
        $contents = (string)file_get_contents($tcaOverridePath);

        self::assertStringNotContainsString(
            'nodeRegistry',
            $contents,
            'Configuration/TCA/Overrides/pages.php must not write to nodeRegistry — that belongs in ext_localconf.php (issue #11).',
        );
    }

    /**
     * @param array<int|string, array{nodeName?: string}> $registry
     * @return array<string, array{nodeName: string, class: string, priority: int}>
     */
    private function indexByNodeName(array $registry): array
    {
        $byName = [];
        foreach ($registry as $entry) {
            if (!is_array($entry) || !isset($entry['nodeName'])) {
                continue;
            }
            $byName[$entry['nodeName']] = $entry;
        }
        return $byName;
    }
}
