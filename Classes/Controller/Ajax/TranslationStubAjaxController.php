<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Controller\Ajax;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\TranslationStubService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend AJAX endpoint for the "Generate translation stub" wizard.
 *
 * Route: GET/POST /typo3/ajax/ai-editorial-helper/translate
 *
 * Inputs:
 *   - contentUid (int, required): the tt_content row currently being edited.
 *   - targetLang (string, optional): "de" or "en". When omitted, the controller
 *     resolves the row's sys_language_uid via the site config.
 *
 * The controller looks up the source row via tt_content.l10n_source. If
 * l10n_source is 0 (the row is the original), we refuse — there's nothing
 * to translate from.
 */
final class TranslationStubAjaxController
{
    private const VALID_LANGS = ['de', 'en'];

    public function __construct(
        private readonly TranslationStubService $service,
    ) {
    }

    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        // PSR-7: getParsedBody() is null for GET requests — coalesce.
        $params = ($request->getParsedBody() ?? []) + $request->getQueryParams();
        $contentUid = (int)($params['contentUid'] ?? 0);
        $targetOverride = strtolower(trim((string)($params['targetLang'] ?? '')));
        $sourceOverride = strtolower(trim((string)($params['sourceLang'] ?? '')));

        if ($contentUid <= 0) {
            return $this->error('Missing or invalid contentUid parameter.', 400);
        }

        $row = BackendUtility::getRecord('tt_content', $contentUid);
        if (!is_array($row)) {
            return $this->error(sprintf('tt_content row %d not found.', $contentUid), 404);
        }

        $sourceUid = (int)($row['l10n_source'] ?? 0);
        if ($sourceUid <= 0) {
            return $this->error(
                'This row has no source record (l10n_source=0). Translation stubs only apply to localised rows.',
                400,
            );
        }

        $sourceRow = BackendUtility::getRecord('tt_content', $sourceUid);
        if (!is_array($sourceRow)) {
            return $this->error(sprintf('Source row %d not found.', $sourceUid), 404);
        }

        $sourceBody = (string)($sourceRow['bodytext'] ?? '');
        if (trim($sourceBody) === '') {
            return $this->error('Source row has no bodytext to translate.', 400);
        }

        try {
            $sourceLang = $sourceOverride !== ''
                ? $sourceOverride
                : $this->resolveLanguageCode((int)($sourceRow['pid'] ?? 0), (int)($sourceRow['sys_language_uid'] ?? 0));
            $targetLang = $targetOverride !== ''
                ? $targetOverride
                : $this->resolveLanguageCode((int)($row['pid'] ?? 0), (int)($row['sys_language_uid'] ?? 0));
        } catch (\Throwable $e) {
            return $this->error('Cannot determine language pair: ' . $e->getMessage(), 400);
        }

        if (!in_array($sourceLang, self::VALID_LANGS, true)) {
            return $this->error(sprintf('Unsupported source language "%s". Supported: %s.', $sourceLang, implode(', ', self::VALID_LANGS)), 400);
        }
        if (!in_array($targetLang, self::VALID_LANGS, true)) {
            return $this->error(sprintf('Unsupported target language "%s". Supported: %s.', $targetLang, implode(', ', self::VALID_LANGS)), 400);
        }

        try {
            $translated = $this->service->translate($sourceBody, $sourceLang, $targetLang);
        } catch (LmStudioException $e) {
            return $this->error($this->humanizeException($e), 502);
        }

        return new JsonResponse([
            'success' => true,
            'translation' => $translated,
            'sourceLang' => $sourceLang,
            'targetLang' => $targetLang,
        ]);
    }

    /**
     * Resolve the two-letter language code for a given page UID + sys_language_uid.
     *
     * Falls back to "en" for sys_language_uid=0 if no site config is present.
     */
    private function resolveLanguageCode(int $pageUid, int $sysLanguageUid): string
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        try {
            $site = $siteFinder->getSiteByPageId($pageUid);
            $language = $site->getLanguageById($sysLanguageUid);
            $tag = $language->getLocale()?->getLanguageCode() ?? $language->getTwoLetterIsoCode();
            return strtolower((string)$tag);
        } catch (\Throwable) {
            // No site config / unknown page. Fall back: assume default-language is English.
            return $sysLanguageUid === 0 ? 'en' : 'en';
        }
    }

    private function humanizeException(LmStudioException $e): string
    {
        return match ($e->getCode()) {
            LmStudioException::CODE_UNREACHABLE
                => 'LM Studio is not reachable. Start LM Studio and load the configured model, then retry.',
            LmStudioException::CODE_NO_MODEL_LOADED
                => 'No model is loaded in LM Studio. Load the configured model in the LM Studio UI and retry.',
            LmStudioException::CODE_INVALID_RESPONSE
                => 'The model returned an unusable translation. Try again, or switch to a more capable model.',
            default => 'LM Studio request failed: ' . $e->getMessage(),
        };
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'error' => $message], $status);
    }
}
