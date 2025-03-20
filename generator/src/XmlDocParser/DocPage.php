<?php

declare(strict_types=1);

namespace Safe\XmlDocParser;

use Safe\Generator\FileCreator;

use function explode;
use function strpos;

class DocPage
{
    public function __construct(private readonly string $path)
    {
    }

    public static function findDocDir(): string
    {
        return __DIR__ . '/../../doc';
    }

    public static function findReferenceDir(): string
    {
        return DocPage::findDocDir() . '/doc-en/en/reference';
    }

    // Ignore function if it was removed before PHP 8.1
    private function getIsDeprecated(string $file): bool
    {
        if (preg_match('/&warn\.deprecated\.function-(\d+-\d+-\d+)\.removed-(\d+-\d+-\d+)/', $file, $matches)) {
            $removedVersion = $matches[2];
            [$major, $minor] = explode('-', $removedVersion);
            if ($major < 8 || ($major == 8 && $minor == 0)) {
                return true;
            }
        }

        if (preg_match('/&warn\.removed\.function-(\d+-\d+-\d+)/', $file, $matches)) {
            $removedVersion = $matches[1];
            [$major, $minor] = explode('-', $removedVersion);
            if ($major < 8 || ($major == 8 && $minor == 0)) {
                return true;
            }
        }

        return false;
    }

    public function getErrorType(): ErrorType
    {
        $file = file_get_contents($this->path);
        if ($file === false) {
            throw new \RuntimeException('An error occurred while reading '.$this->path);
        }
        if ($this->getIsDeprecated($file)) {
            return ErrorType::UNKNOWN;
        }

        // Only evaluate the text inside the `<refsect1 role="returnvalues">...</refsect1>` section of the doc page.
        // This minimizes 'false positives', where text such as "returns false when ..." could be matched outside
        // the function's dedicated Return Values section.
        $returnDocs = $this->extractSection('returnvalues', $file);
        $detectErrorType = require FileCreator::getSafeRootDir() . '/generator/config/detectErrorType.php';
        return $detectErrorType($returnDocs);
    }

    /**
     * @return \SimpleXMLElement[]
     */
    public function getMethodSynopsis(): array
    {
        /** @var string[] $cleanedFunctions */
        $cleanedFunctions = [];

        $file = \file_get_contents($this->path);
        if ($file === false) {
            throw new \RuntimeException('An error occurred while reading '.$this->path);
        }

        // Only evaluate the synopsis inside the `<refsect1 role="description">...</refsect1>` section of the doc page.
        // Other synopses might occur in the `<refsect1 role="parameters">...</refsect1>` section, but these describe
        // handlers, callbacks, and other callable-type arguments, not the function itself.
        $fileDescriptionSection = $this->extractSection('description', $file);

        if (!preg_match_all('/<\/?methodsynopsis[\s\S]*?>[\s\S]*?<\/methodsynopsis>/m', $fileDescriptionSection, $functions, PREG_SET_ORDER, 0)) {
            return [];
        }

        $functions = $this->arrayFlatten($functions);
        foreach ($functions as $function) {
            $cleaningFunction = \str_replace(['&false;', '&true;', '&null;'], ['false', 'true', 'null'], $function);
            $cleaningFunction = preg_replace('/&(.*);/m', '', $cleaningFunction);
            if (!\is_string($cleaningFunction)) {
                throw new \RuntimeException('Error occurred in preg_replace');
            }
            $cleanedFunctions[] = $cleaningFunction;
        }
        $functionObjects = [];
        foreach ($cleanedFunctions as $cleanedFunction) {
            $functionObject = \simplexml_load_string($cleanedFunction);
            if ($functionObject) {
                $functionObjects[] = $functionObject;
            }
        }
        return $functionObjects;
    }

    /**
     * Loads the XML file, resolving all DTD declared entities.
     */
    public function loadAndResolveFile(): \SimpleXMLElement
    {
        // Prepare generated entities.
        $entitiesPath = \realpath(DocPage::findDocDir() . '/entities/generated.ent');
        $entities = '<!DOCTYPE refentry SYSTEM "' . $entitiesPath . '">';

        if (!\file_exists($entitiesPath)) {
            self::buildEntities();
        }

        // Preprocess file before loading it as XML.
        $pathsToProcess = [\realpath($this->path)];

        for ($i = 0; $i < count($pathsToProcess); $i++) {
            // Read file contents.
            $content = \file_get_contents($pathsToProcess[$i]);
            if ($content === false) {
                throw new \Exception('Failed to read ' . $pathsToProcess[$i] . ' during processing of ' . $this->path);
            }

            // If file has already been processed, skip it.
            if (strpos($content, $entities) !== false) {
                continue;
            }

            // Prepend file with generated entities.
            $strpos = \strpos($content, '?>') + 2;
            $content = \substr($content, 0, $strpos) . $entities . \substr($content, $strpos + 1);

            // Gather includes.
            $includeCheck = \preg_match_all('/<xi:include.*?id\(\'(?<id>.*?)\'\).*?>/', $content, $includeMatches);
            if ($includeCheck === false) {
                throw new \RuntimeException('Failed to determine includes in ' . $pathsToProcess[$i] . ' during processing of ' . $this->path);
            }

            // Determine and append href to includes.
            $includedIds = array_unique($includeMatches['id']);

            foreach ($includedIds as $includedId) {
                // Determine location of include.
                $escapedId = \escapeshellarg('xml:id="' . $includedId . '"');
                $escapedDocDir = \escapeshellarg(\realpath(DocPage::findDocDir()));
                $execCheck = \exec('grep -lr ' . $escapedId . ' ' . $escapedDocDir, $output);

                if ($execCheck === false || \count($output) !== 1) {
                    throw new \Exception('Failed to find ID ' . $includedId . ' during processing of ' . $this->path);
                }

                // Append href to include.
                $content = \preg_replace(
                    '/<xi:include(?=.*?id\(\'' . \preg_quote($includedId) . '\'\))/',
                    '<xi:include href="' . $output[0] . '" ',
                    $content
                );

                // Queue included file for processing.
                if (!in_array($output[0], $pathsToProcess)) {
                    $pathsToProcess[] = $output[0];
                }
            }

            // Update file contents.
            \file_put_contents($pathsToProcess[$i], $content);
        }

        // Parse main file.
        $elem = \simplexml_load_file($this->path, \SimpleXMLElement::class, LIBXML_DTDLOAD | LIBXML_NOENT);
        if ($elem === false) {
            throw new \RuntimeException('Invalid XML file for '.$this->path);
        }
        $elem->registerXPathNamespace('docbook', 'http://docbook.org/ns/docbook');

        // Parse includes.
        $dom = dom_import_simplexml($elem);
        $dom->ownerDocument->xinclude();

        return $elem;
    }

    /**
     * Returns the module name in Camelcase.
     */
    public function getModule(): string
    {
        return $this->toCamelCase(\basename(\dirname($this->path, 2)));
    }

    private function extractSection(string $sectionName, string $file): string
    {
        $regexpBase = '/<refsect1\s+role="%s">[\s\S]*?<\/refsect1>/m';
        $regexpString = sprintf($regexpBase, preg_quote($sectionName, '/'));
        preg_match_all($regexpString, $file, $output);
        $output = implode('', $this->arrayFlatten((array) $output));
        return $output;
    }

    private function toCamelCase(string $str): string
    {
        $tokens = preg_split("/[_ ]+/", $str);
        if ($tokens === false) {
            throw new \RuntimeException('Unexpected preg_split error'); // @codeCoverageIgnore
        }

        $str = '';
        foreach ($tokens as $token) {
            $str .= ucfirst($token);
        }

        return $str;
    }

    /**
     * @param mixed[] $array multidimensional string array
     * @return string[]
     */
    private function arrayFlatten(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = array_merge($result, $this->arrayFlatten($value));
            } else {
                $result[$key] = strval($value);
            }
        }
        return $result;
    }

    public static function buildEntities(): void
    {
        $file1 = \file_get_contents(DocPage::findDocDir() . '/doc-en/en/language-defs.ent') ?: '';
        $file2 = \file_get_contents(DocPage::findDocDir() . '/doc-en/en/language-snippets.ent') ?: '';
        $file3 = \file_get_contents(DocPage::findDocDir() . '/doc-en/en/extensions.ent') ?: '';
        $file4 = \file_get_contents(DocPage::findDocDir() . '/doc-en/doc-base/entities/global.ent') ?: '';

        $completeFile = $file1 . self::extractXmlHeader($file2) . self::extractXmlHeader($file3) . $file4;

        \file_put_contents(DocPage::findDocDir() . '/entities/generated.ent', $completeFile);
    }

    private static function extractXmlHeader(string $content): string
    {
        $strpos = strpos($content, '?>')+2;
        return substr($content, $strpos);
    }
}
