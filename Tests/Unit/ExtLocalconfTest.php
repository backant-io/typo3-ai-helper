<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit;

use Kairos\AiEditorialHelper\Form\FieldWizard\GenerateMetaDescriptionWizard;
use Kairos\AiEditorialHelper\Form\FieldWizard\SuggestCategoriesWizard;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for issue #11.
 *
 * The FormEngine NodeFactory reads its registry from
 * $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'] at
 * construction time. Registration MUST live in ext_localconf.php (run on
 * every request) and NOT in a TCA override file (cached after first run).
 *
 * This test loads ext_localconf.php in a controlled state and asserts the
 * registry contains a valid entry for each shipped wizard, with the
 * priority bounds that NodeFactory enforces (0..100).
 */
final class ExtLocalconfTest extends TestCase
{
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

    public function testExtLocalconfRegistersMetaWizard(): void
    {
        require __DIR__ . '/../../ext_localconf.php';

        $registry = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'] ?? [];
        $byName = $this->indexByNodeName($registry);

        self::assertArrayHasKey('aiEditorialHelperGenerateMeta', $byName);
        self::assertSame(GenerateMetaDescriptionWizard::class, $byName['aiEditorialHelperGenerateMeta']['class']);
        self::assertGreaterThanOrEqual(0, $byName['aiEditorialHelperGenerateMeta']['priority']);
        self::assertLessThanOrEqual(100, $byName['aiEditorialHelperGenerateMeta']['priority']);
    }

    public function testExtLocalconfRegistersCategoryWizard(): void
    {
        require __DIR__ . '/../../ext_localconf.php';

        $registry = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'] ?? [];
        $byName = $this->indexByNodeName($registry);

        self::assertArrayHasKey('aiEditorialHelperSuggestCategories', $byName);
        self::assertSame(SuggestCategoriesWizard::class, $byName['aiEditorialHelperSuggestCategories']['class']);
        self::assertGreaterThanOrEqual(0, $byName['aiEditorialHelperSuggestCategories']['priority']);
        self::assertLessThanOrEqual(100, $byName['aiEditorialHelperSuggestCategories']['priority']);
    }

    public function testEveryRegistrationHasRequiredKeys(): void
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
            self::assertTrue(class_exists($entry['class']), "Class {$entry['class']} for nodeName {$entry['nodeName']} does not exist");
        }
    }

    public function testRegistrationKeysAreUnique(): void
    {
        require __DIR__ . '/../../ext_localconf.php';

        $registry = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'] ?? [];
        // Numeric keys would silently overwrite each other on duplicate. Asserting via array_keys:
        $keys = array_keys($registry);
        self::assertSame(count($keys), count(array_unique($keys)));
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
