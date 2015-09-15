<?php
namespace PHPePub\Core;

use com\grandt\BinStringStatic;
use DOMDocument;
use DOMNode;
use grandt\ResizeGif\ResizeGif;
use PHPePub\Core\Structure\Ncx;
use PHPePub\Core\Structure\NCX\NavPoint;
use PHPePub\Core\Structure\Opf;
use PHPePub\Core\Structure\OPF\DublinCore;
use PHPePub\Core\Structure\OPF\MarcCode;
use PHPePub\Core\Structure\OPF\Reference;
use PHPZip\Zip\File\Zip;
use RelativePath;


/**
 * Create an ePub compatible book file.
 *
 * Please note, once finalized a book can no longer have chapters of data added or changed.
 *
 * License: GNU LGPL 2.1.
 *
 * Thanks to: Adam Schmalhofer and Kirstyn Fox for invaluable input and for "nudging" me in the right direction :)
 *
 * @author    A. Grandt <php@grandt.com>
 * @copyright 2009-2014 A. Grandt
 * @license   GNU LGPL 2.1
 * @version   4.0.0
 * @link      http://www.phpclasses.org/package/6115
 * @link      https://github.com/Grandt/PHPePub
 */
class EPub {
    const VERSION = '4.0.0';

    const IDENTIFIER_UUID = 'UUID';
    const IDENTIFIER_URI = 'URI';
    const IDENTIFIER_ISBN = 'ISBN';

    /** Ignore all external references, and do not process the file for these */
    const EXTERNAL_REF_IGNORE = 0;
    /** Process the file for external references and add them to the book */
    const EXTERNAL_REF_ADD = 1;
    /** Process the file for external references and add them to the book, but remove images, and img tags */
    const EXTERNAL_REF_REMOVE_IMAGES = 2;
    /** Process the file for external references and add them to the book, but replace images, and img tags with [image] */
    const EXTERNAL_REF_REPLACE_IMAGES = 3;

    const DIRECTION_LEFT_TO_RIGHT = 'ltr';
    const DIRECTION_RIGHT_TO_LEFT = 'rtl';

    const BOOK_VERSION_EPUB2 = '2.0';
    const BOOK_VERSION_EPUB3 = '3.0';
    public $maxImageWidth = 768;
    public $maxImageHeight = 1024;
    public $splitDefaultSize = 250000;
    /** Gifs can crash some early ADE based readers, and are disabled by default.
     * getImage will convert these if it can, unless this is set to TRUE.
     */
    public $isGifImagesEnabled = false;
    public $isReferencesAddedToToc = true;
    /**
     * Used for building the TOC.
     * If this list is overwritten it MUST contain at least "text" as an element.
     */
    public $referencesOrder = null;
    public $pluginDir = 'extLib';
    public $isLogging = true;
    public $encodeHTML = false;
    protected $isCurlInstalled;
    protected $isGdInstalled;
    protected $isExifInstalled;
    protected $isFileGetContentsInstalled;
    protected $isFileGetContentsExtInstalled;
    protected $isAnimatedGifResizeInstalled = false;
    private $bookVersion = EPub::BOOK_VERSION_EPUB2;
    /** @var $Zip Zip */
    private $zip;
    private $title = '';
    private $language = 'en';
    private $identifier = '';
    private $identifierType = '';
    private $description = '';
    private $author = '';
    private $authorSortKey = '';
    private $publisherName = '';
    private $publisherURL = '';
    private $date = 0;
    private $rights = '';
    private $coverage = '';
    private $relation = '';
    private $sourceURL = '';
    private $chapterCount = 0;
    /** @var $opf Opf */
    private $opf = null;
    /** @var $ncx Ncx */
    private $ncx = null;
    private $isFinalized = false;
    private $isInitialized = false;
    private $isCoverImageSet = false;
    private $buildTOC = false; // ISO 8601 long
    private $tocTitle = null; // short date format to placate ePubChecker.
    private $tocFileName = null;
    private $tocNavAdded = false;
    private $tocCSSClass = null;
    private $tocAddReferences = false;
    private $tocCssFileName = null;
    private $fileList = array();
    private $writingDirection = EPub::DIRECTION_LEFT_TO_RIGHT;
    private $languageCode = 'en';
    private $dateformat = 'Y-m-d\TH:i:s.000000P';
    private $dateformatShort = 'Y-m-d';
    private $headerDateFormat = "D, d M Y H:i:s T";
    private $docRoot = null;
    private $bookRoot = 'OEBPS/';
    private $EPubMark = true;
    private $generator = '';
    private $log = null;
    private $htmlContentHeader = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\"\n    \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">\n<html xmlns=\"http://www.w3.org/1999/xhtml\">\n<head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />\n<title></title>\n</head>\n<body>\n";
    private $htmlContentFooter = "</body>\n</html>\n";

    /**
     * Class constructor.
     *
     * @param string $bookVersion
     * @param string $languageCode
     * @param string $writingDirection
     */
    function __construct($bookVersion = EPub::BOOK_VERSION_EPUB2, $languageCode = 'en', $writingDirection = EPub::DIRECTION_LEFT_TO_RIGHT) {
        $this->bookVersion = $bookVersion;
        $this->writingDirection = $writingDirection;
        $this->languageCode = $languageCode;

        $this->log = new Logger('EPub', $this->isLogging);

        /* Prepare Logging. Just in case it's used. later */
        if ($this->isLogging) {
            $this->log->logLine('EPub class version....: ' . self::VERSION);
            $this->log->dumpInstalledModules();
        }
        $this->setUp();
    }

    private function setUp() {
        $this->referencesOrder = array(
            Reference::COVER                 => 'Cover Page',
            Reference::TITLE_PAGE            => 'Title Page',
            Reference::ACKNOWLEDGEMENTS      => 'Acknowledgements',
            Reference::BIBLIOGRAPHY          => 'Bibliography',
            Reference::COLOPHON              => 'Colophon',
            Reference::COPYRIGHT_PAGE        => 'Copyright',
            Reference::DEDICATION            => 'Dedication',
            Reference::EPIGRAPH              => 'Epigraph',
            Reference::FOREWORD              => 'Foreword',
            Reference::TABLE_OF_CONTENTS     => 'Table of Contents',
            Reference::NOTES                 => 'Notes',
            Reference::PREFACE               => 'Preface',
            Reference::TEXT                  => 'First Page',
            Reference::LIST_OF_ILLUSTRATIONS => 'List of Illustrations',
            Reference::LIST_OF_TABLES        => 'List of Tables',
            Reference::GLOSSARY              => 'Glossary',
            Reference::INDEX                 => 'Index'
        );

        $this->docRoot = filter_input(INPUT_SERVER, 'DOCUMENT_ROOT') . '/';

        $this->isCurlInstalled = extension_loaded('curl') && function_exists('curl_version');
        $this->isGdInstalled = (extension_loaded('gd') || extension_loaded('gd2')) && function_exists('gd_info');
        $this->isExifInstalled = extension_loaded('exif') && function_exists('exif_imagetype');
        $this->isFileGetContentsInstalled = function_exists('file_get_contents');
        $this->isFileGetContentsExtInstalled = $this->isFileGetContentsInstalled && ini_get('allow_url_fopen');

        $this->zip = new Zip();
        $this->zip->setExtraField(false);
        $this->zip->addFile('application/epub+zip', 'mimetype');
        $this->zip->setExtraField(true);
        $this->zip->addDirectory('META-INF');

        $this->ncx = new Ncx(null, null, null, $this->languageCode, $this->writingDirection);
        $this->opf = new Opf();
    }

    /**
     * Class destructor
     *
     * @return void
     * @TODO make sure elements in the destructor match the current class elements
     */
    function __destruct() {
        unset($this->bookVersion, $this->maxImageWidth, $this->maxImageHeight);
        unset($this->splitDefaultSize, $this->isGifImagesEnabled, $this->isReferencesAddedToToc);
        unset($this->zip, $this->title, $this->language, $this->identifier, $this->identifierType);
        unset($this->description, $this->author, $this->authorSortKey, $this->publisherName);
        unset($this->publisherURL, $this->date, $this->rights, $this->coverage, $this->relation);
        unset($this->sourceURL, $this->chapterCount, $this->opf, $this->ncx, $this->isFinalized);
        unset($this->isCoverImageSet, $this->fileList, $this->writingDirection, $this->languageCode);
        unset($this->referencesOrder, $this->dateformat, $this->dateformatShort, $this->headerDateFormat);
        unset($this->isCurlInstalled, $this->isGdInstalled, $this->isExifInstalled);
        unset($this->isFileGetContentsInstalled, $this->isFileGetContentsExtInstalled, $this->bookRoot);
        unset($this->docRoot, $this->EPubMark, $this->generator, $this->log, $this->isLogging);
        unset($this->encodeHTML, $this->htmlContentHeader, $this->htmlContentFooter);
        unset($this->buildTOC, $this->tocTitle, $this->tocCSSClass, $this->tocAddReferences);
        unset($this->tocFileName, $this->tocCssFileName);
    }

    /**
     * Add a chapter to the book, as a chapter should not exceed 250kB, you can parse an array with multiple parts as $chapterData.
     * These will still only show up as a single chapter in the book TOC.
     *
     * @param string $chapterName        Name of the chapter, will be use din the TOC
     * @param string $fileName           Filename to use for the chapter, must be unique for the book.
     * @param string $chapterData        Chapter text in XHTML or array $chapterData valid XHTML data for the chapter. File should NOT exceed 250kB.
     * @param bool   $autoSplit          Should the chapter be split if it exceeds the default split size? Default=FALSE, only used if $chapterData is a string.
     * @param int    $externalReferences How to handle external references, EPub::EXTERNAL_REF_IGNORE, EPub::EXTERNAL_REF_ADD or EPub::EXTERNAL_REF_REMOVE_IMAGES? See documentation for <code>processChapterExternalReferences</code> for explanation. Default is EPub::EXTERNAL_REF_IGNORE.
     * @param string $baseDir            Default is "", meaning it is pointing to the document root. NOT used if $externalReferences is set to EPub::EXTERNAL_REF_IGNORE.
     *
     * @return mixed $success            FALSE if the addition failed, else the new NavPoint.
     */
    function addChapter($chapterName, $fileName, $chapterData = null, $autoSplit = false, $externalReferences = EPub::EXTERNAL_REF_IGNORE, $baseDir = "") {
        if ($this->isFinalized) {
            return false;
        }
        $fileName = RelativePath::getRelativePath($fileName);
        $fileName = preg_replace('#^[/\.]+#i', "", $fileName);
        $navPoint = false;

        $chapter = $chapterData;
        if ($autoSplit && is_string($chapterData) && mb_strlen($chapterData) > $this->splitDefaultSize) {
            $splitter = new EPubChapterSplitter();

            $chapterArray = $splitter->splitChapter($chapterData);
            if (count($chapterArray) > 1) {
                $chapter = $chapterArray;
            }
        }

        if (!empty($chapter) && is_string($chapter)) {
            if ($externalReferences !== EPub::EXTERNAL_REF_IGNORE) {
                $htmlDirInfo = pathinfo($fileName);
                $htmlDir = preg_replace('#^[/\.]+#i', "", $htmlDirInfo["dirname"] . "/");
                $this->processChapterExternalReferences($chapter, $externalReferences, $baseDir, $htmlDir);
            }

            if ($this->encodeHTML === true) {
                $chapter = $this->encodeHtml($chapter);
            }

            $this->chapterCount++;
            $this->addFile($fileName, "chapter" . $this->chapterCount, $chapter, "application/xhtml+xml");
            $this->opf->addItemRef("chapter" . $this->chapterCount);

            $navPoint = new NavPoint($this->decodeHtmlEntities($chapterName), $fileName, "chapter" . $this->chapterCount);
            $this->ncx->addNavPoint($navPoint);
            $this->ncx->chapterList[$chapterName] = $navPoint;
        } elseif (is_array($chapter)) {
            $fileNameParts = pathinfo($fileName);
            $extension = $fileNameParts['extension'];
            $name = $fileNameParts['filename'];

            $partCount = 0;
            $this->chapterCount++;

            $oneChapter = each($chapter);
            while ($oneChapter) {
                /** @noinspection PhpUnusedLocalVariableInspection */
                list($k, $v) = $oneChapter;
                if ($this->encodeHTML === true) {
                    $v = $this->encodeHtml($v);
                }

                if ($externalReferences !== EPub::EXTERNAL_REF_IGNORE) {
                    $this->processChapterExternalReferences($v, $externalReferences, $baseDir);
                }
                $partCount++;
                $partName = $name . "_" . $partCount;
                $this->addFile($partName . "." . $extension, $partName, $v, "application/xhtml+xml");
                $this->opf->addItemRef($partName);

                $oneChapter = each($chapter);
            }
            $partName = $name . "_1." . $extension;
            $navPoint = new NavPoint($this->decodeHtmlEntities($chapterName), $partName, $partName);
            $this->ncx->addNavPoint($navPoint);

            $this->ncx->chapterList[$chapterName] = $navPoint;
        } elseif (!isset($chapterData) && strpos($fileName, "#") > 0) {
            $this->chapterCount++;
            //$this->opf->addItemRef("chapter" . $this->chapterCount);

            $navPoint = new NavPoint($this->decodeHtmlEntities($chapterName), $fileName, "chapter" . $this->chapterCount);
            $this->ncx->addNavPoint($navPoint);
            $this->ncx->chapterList[$chapterName] = $navPoint;
        } elseif (!isset($chapterData) && $fileName == "TOC.xhtml") {
            $this->chapterCount++;
            $this->opf->addItemRef("toc");

            $navPoint = new NavPoint($this->decodeHtmlEntities($chapterName), $fileName, "chapter" . $this->chapterCount);
            $this->ncx->addNavPoint($navPoint);
            $this->ncx->chapterList[$chapterName] = $navPoint;
            $this->tocNavAdded = true;
        }

        return $navPoint;
    }

    /**
     * Process external references from a HTML to the book. The chapter itself is not stored.
     * the HTML is scanned for &lt;link..., &lt;style..., and &lt;img tags.
     * Embedded CSS styles and links will also be processed.
     * Script tags are not processed, as scripting should be avoided in e-books.
     *
     * EPub keeps track of added files, and duplicate files referenced across multiple
     *  chapters, are only added once.
     *
     * If the $doc is a string, it is assumed to be the content of an HTML file,
     *  else is it assumes to be a DOMDocument.
     *
     * Basedir is the root dir the HTML is supposed to "live" in, used to resolve
     *  relative references such as <code>&lt;img src="../images/image.png"/&gt;</code>
     *
     * $externalReferences determines how the function will handle external references.
     *
     * @param mixed  &$doc               (referenced)
     * @param int    $externalReferences How to handle external references, EPub::EXTERNAL_REF_IGNORE, EPub::EXTERNAL_REF_ADD or EPub::EXTERNAL_REF_REMOVE_IMAGES? Default is EPub::EXTERNAL_REF_ADD.
     * @param string $baseDir            Default is "", meaning it is pointing to the document root.
     * @param string $htmlDir            The path to the parent HTML file's directory from the root of the archive.
     *
     * @return bool  false if unsuccessful (book is finalized or $externalReferences == EXTERNAL_REF_IGNORE).
     */
    protected function processChapterExternalReferences(&$doc, $externalReferences = EPub::EXTERNAL_REF_ADD, $baseDir = "", $htmlDir = "") {
        if ($this->isFinalized || $externalReferences === EPub::EXTERNAL_REF_IGNORE) {
            return false;
        }

        $backPath = preg_replace('#[^/]+/#i', "../", $htmlDir);
        $isDocAString = is_string($doc);
        $xmlDoc = null;

        if ($isDocAString) {
            $doc = self::removeComments($doc);

            $xmlDoc = new DOMDocument();
            @$xmlDoc->loadHTML($doc);
        } else {
            $xmlDoc = $doc;
        }

        $this->processChapterStyles($xmlDoc, $externalReferences, $baseDir, $htmlDir);
        $this->processChapterLinks($xmlDoc, $externalReferences, $baseDir, $htmlDir, $backPath);
        $this->processChapterImages($xmlDoc, $externalReferences, $baseDir, $htmlDir, $backPath);
        $this->processChapterSources($xmlDoc, $externalReferences, $baseDir, $htmlDir, $backPath);

        if ($isDocAString) {
            //$html = $xmlDoc->saveXML();

            $htmlNode = $xmlDoc->getElementsByTagName("html");
            $headNode = $xmlDoc->getElementsByTagName("head");
            $bodyNode = $xmlDoc->getElementsByTagName("body");

            $htmlNS = "";
            for ($index = 0; $index < $htmlNode->item(0)->attributes->length; $index++) {
                $nodeName = $htmlNode->item(0)->attributes->item($index)->nodeName;
                $nodeValue = $htmlNode->item(0)->attributes->item($index)->nodeValue;

                if ($nodeName != "xmlns") {
                    $htmlNS .= " $nodeName=\"$nodeValue\"";
                }
            }

            $xml = new DOMDocument('1.0', "utf-8");
            $xml->lookupPrefix("http://www.w3.org/1999/xhtml");
            $xml->preserveWhiteSpace = false;
            $xml->formatOutput = true;

            $xml2Doc = new DOMDocument('1.0', "utf-8");
            $xml2Doc->lookupPrefix("http://www.w3.org/1999/xhtml");
            $xml2Doc->loadXML("<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\"\n"
                . "   \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">\n"
                . "<html xmlns=\"http://www.w3.org/1999/xhtml\"" . $htmlNS . ">\n</html>\n");
            $html = $xml2Doc->getElementsByTagName("html")->item(0);
            $html->appendChild($xml2Doc->importNode($headNode->item(0), true));
            $html->appendChild($xml2Doc->importNode($bodyNode->item(0), true));

            // force pretty printing and correct formatting, should not be needed, but it is.
            $xml->loadXML($xml2Doc->saveXML());
            $doc = $xml->saveXML();

            if (!$this->isEPubVersion2()) {
                $doc = preg_replace('#^\s*<!DOCTYPE\ .+?>\s*#im', '', $doc);
            }
        }

        return true;
    }

    /**
     * @param $doc
     *
     * @return string
     */
    public static function removeComments($doc) {
        $doc = preg_replace('~--\s+>~', '-->', $doc);
        $doc = preg_replace('~<\s*!\s*--~', '<!--', $doc);
        $cPos = BinStringStatic::_strpos($doc, "<!--");
        if ($cPos !== false) {
            $startCount = substr_count($doc, "<!--");
            $endCount = substr_count($doc, "-->");

            $lastCPos = -1;

            while ($cPos !== false && $lastCPos != $cPos) {
                $lastCPos = $cPos;
                $lastEPos = $cPos;
                $ePos = $cPos;
                do {
                    $ePos = BinStringStatic::_strpos($doc, "-->", $ePos + 1);
                    if ($ePos !== false) {
                        $lastEPos = $ePos;
                        $comment = BinStringStatic::_substr($doc, $cPos, ($lastEPos + 3) - $cPos);
                        $startCount = substr_count($comment, "<!--");
                        $endCount = substr_count($comment, "-->");
                    } elseif ($lastEPos == $cPos) {
                        $lastEPos = BinStringStatic::_strlen($doc) - 3;
                    }
                } while ($startCount != $endCount && $ePos !== false);

                $doc = substr_replace($doc, "", $cPos, ($lastEPos + 3) - $cPos);
                $cPos = BinStringStatic::_strpos($doc, "<!--");
            }
        }

        // print "<pre>\n" . htmlentities($doc) . "\n</pre>\n";
        return $doc;
    }

    /**
     * Process style tags in a DOMDocument. Styles will be passed as CSS files and reinserted into the document.
     *
     * @param DOMDocument &$xmlDoc            (referenced)
     * @param int         $externalReferences How to handle external references, EPub::EXTERNAL_REF_IGNORE, EPub::EXTERNAL_REF_ADD or EPub::EXTERNAL_REF_REMOVE_IMAGES? Default is EPub::EXTERNAL_REF_ADD.
     * @param string      $baseDir            Default is "", meaning it is pointing to the document root.
     * @param string      $htmlDir            The path to the parent HTML file's directory from the root of the archive.
     *
     * @return bool  FALSE if uncuccessful (book is finalized or $externalReferences == EXTERNAL_REF_IGNORE).
     */
    protected function processChapterStyles(&$xmlDoc, $externalReferences = EPub::EXTERNAL_REF_ADD, $baseDir = "", $htmlDir = "") {
        if ($this->isFinalized || $externalReferences === EPub::EXTERNAL_REF_IGNORE) {
            return false;
        }
        // process inlined CSS styles in style tags.
        $styles = $xmlDoc->getElementsByTagName("style");
        $styleCount = $styles->length;
        for ($styleIdx = 0; $styleIdx < $styleCount; $styleIdx++) {
            $style = $styles->item($styleIdx);

            $styleData = preg_replace('#[/\*\s]*\<\!\[CDATA\[[\s\*/]*#im', "", $style->nodeValue);
            $styleData = preg_replace('#[/\*\s]*\]\]\>[\s\*/]*#im', "", $styleData);

            $this->processCSSExternalReferences($styleData, $externalReferences, $baseDir, $htmlDir);
            $style->nodeValue = "\n" . trim($styleData) . "\n";
        }

        return true;
    }

    /**
     * Process images referenced from an CSS file to the book.
     *
     * $externalReferences determins how the function will handle external references.
     *
     * @param string &$cssFile           (referenced)
     * @param int    $externalReferences How to handle external references, EPub::EXTERNAL_REF_IGNORE, EPub::EXTERNAL_REF_ADD or EPub::EXTERNAL_REF_REMOVE_IMAGES? Default is EPub::EXTERNAL_REF_ADD.
     * @param string $baseDir            Default is "", meaning it is pointing to the document root.
     * @param string $cssDir             The of the CSS file's directory from the root of the archive.
     *
     * @return bool  FALSE if unsuccessful (book is finalized or $externalReferences == EXTERNAL_REF_IGNORE).
     */
    protected function processCSSExternalReferences(&$cssFile, $externalReferences = EPub::EXTERNAL_REF_ADD, $baseDir = "", $cssDir = "") {
        if ($this->isFinalized || $externalReferences === EPub::EXTERNAL_REF_IGNORE) {
            return false;
        }

        $backPath = preg_replace('#[^/]+/#i', "../", $cssDir);
        $imgs = null;
        preg_match_all('#url\s*\([\'\"\s]*(.+?)[\'\"\s]*\)#im', $cssFile, $imgs, PREG_SET_ORDER);

        $itemCount = count($imgs);
        for ($idx = 0; $idx < $itemCount; $idx++) {
            $img = $imgs[$idx];
            if ($externalReferences === EPub::EXTERNAL_REF_REMOVE_IMAGES || $externalReferences === EPub::EXTERNAL_REF_REPLACE_IMAGES) {
                $cssFile = str_replace($img[0], "", $cssFile);
            } else {
                $source = $img[1];

                $pathData = pathinfo($source);
                $internalSrc = $pathData['basename'];
                $internalPath = "";
                $isSourceExternal = false;

                if ($this->resolveImage($source, $internalPath, $internalSrc, $isSourceExternal, $baseDir, $cssDir)) {
                    $cssFile = str_replace($img[0], "url('" . $backPath . $internalPath . "')", $cssFile);
                } elseif ($isSourceExternal) {
                    $cssFile = str_replace($img[0], "", $cssFile); // External image is missing
                } // else do nothing, if the image is local, and missing, assume it's been generated.
            }
        }

        return true;
    }

    /**
     * Resolve an image src and determine it's target location and add it to the book.
     *
     * @param string $source            Image Source link.
     * @param string &$internalPath     (referenced) Return value, will be set to the target path and name in the book.
     * @param string &$internalSrc      (referenced) Return value, will be set to the target name in the book.
     * @param string &$isSourceExternal (referenced) Return value, will be set to TRUE if the image originated from a full URL.
     * @param string $baseDir           Default is "", meaning it is pointing to the document root.
     * @param string $htmlDir           The path to the parent HTML file's directory from the root of the archive.
     *
     * @return bool
     */
    protected function resolveImage($source, &$internalPath, &$internalSrc, &$isSourceExternal, $baseDir = "", $htmlDir = "") {
        if ($this->isFinalized) {
            return false;
        }
        $imageData = null;

        if (preg_match('#^(http|ftp)s?://#i', $source) == 1) {
            $urlinfo = parse_url($source);

            if (strpos($urlinfo['path'], $baseDir . "/") !== false) {
                $internalSrc = $this->sanitizeFileName(urldecode(substr($urlinfo['path'], strpos($urlinfo['path'], $baseDir . "/") + strlen($baseDir) + 1)));
            }
            $internalPath = $urlinfo["scheme"] . "/" . $urlinfo["host"] . "/" . pathinfo($urlinfo["path"], PATHINFO_DIRNAME);
            $isSourceExternal = true;
            $imageData = $this->getImage($source);
        } else {
            if (strpos($source, "/") === 0) {
                $internalPath = pathinfo($source, PATHINFO_DIRNAME);

                $path = $source;
                if (!file_exists($path)) {
                    $path = $this->docRoot . $path;
                }

                $imageData = $this->getImage($path);
            } else {
                $internalPath = $htmlDir . "/" . preg_replace('#^[/\.]+#', '', pathinfo($source, PATHINFO_DIRNAME));

                $path = $baseDir . "/" . $source;
                if (!file_exists($path)) {
                    $path = $this->docRoot . $path;
                }

                $imageData = $this->getImage($path);
            }
        }
        if ($imageData !== false) {
            $iSrcInfo = pathinfo($internalSrc);
            if (!empty($imageData['ext']) && $imageData['ext'] != $iSrcInfo['extension']) {
                $internalSrc = $iSrcInfo['filename'] . "." . $imageData['ext'];
            }
            $internalPath = RelativePath::getRelativePath("images/" . $internalPath . "/" . $internalSrc);
            if (!array_key_exists($internalPath, $this->fileList)) {
                $this->addFile($internalPath, "i_" . $internalSrc, $imageData['image'], $imageData['mime']);
                $this->fileList[$internalPath] = $source;
            }

            return true;
        }

        return false;
    }

    /**
     * Remove disallowed characters from string to get a nearly safe filename
     *
     * @param string $fileName
     *
     * @return mixed|string
     */
    function sanitizeFileName($fileName) {
        $fileName1 = str_replace(StaticData::$forbiddenCharacters, '', $fileName);
        $fileName2 = preg_replace('/[\s-]+/', '-', $fileName1);

        return trim($fileName2, '.-_');
    }

    /**
     * Get an image from a file or url, return it resized if the image exceeds the $maxImageWidth or $maxImageHeight directives.
     *
     * The return value is an array.
     * ['width'] is the width of the image.
     * ['height'] is the height of the image.
     * ['mime'] is the mime type of the image. Resized images are always in jpeg format.
     * ['image'] is the image data.
     * ['ext'] is the extension of the image file.
     *
     * @param string $source path or url to file.
     *
     * @return array|bool
     */
    function getImage($source) {
        $width = -1;
        $height = -1;
        $mime = "application/octet-stream";
        $ext = "";

        $image = $this->getFileContents($source);

        if ($image !== false && strlen($image) > 0) {
            $imageFile = imagecreatefromstring($image);
            if ($imageFile !== false) {
                $width = ImageSX($imageFile);
                $height = ImageSY($imageFile);
            }
            if ($this->isExifInstalled) {
                @$type = exif_imagetype($source);
                $mime = image_type_to_mime_type($type);
            }
            if ($mime === "application/octet-stream") {
                $mime = $this->image_file_type_from_binary($image);
            }
            if ($mime === "application/octet-stream") {
                $mime = $this->getMimeTypeFromUrl($source);
            }
        } else {
            return false;
        }

        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $ratio = 1;

        if ($this->isGdInstalled) {
            if ($width > $this->maxImageWidth) {
                $ratio = $this->maxImageWidth / $width;
            }
            if ($height * $ratio > $this->maxImageHeight) {
                $ratio = $this->maxImageHeight / $height;
            }
            //echo "<pre>\$source: $source\n\$ratio.: $ratio\n\$mime..: $mime\n\$width.: $width\n\$height: $height</pre>\n";
            if ($ratio < 1 || empty($mime)) {
                if ($mime == "image/png" || ($this->isGifImagesEnabled === false && $mime == "image/gif")) {
                    $image_o = imagecreatefromstring($image);
                    $image_p = imagecreatetruecolor($width * $ratio, $height * $ratio);

                    imagealphablending($image_p, false);
                    imagesavealpha($image_p, true);
                    imagealphablending($image_o, true);

                    imagecopyresampled($image_p, $image_o, 0, 0, 0, 0, ($width * $ratio), ($height * $ratio), $width, $height);
                    ob_start();
                    imagepng($image_p, null, 9);
                    $image = ob_get_contents();
                    ob_end_clean();

                    imagedestroy($image_o);
                    imagedestroy($image_p);

                    $ext = "png";
                } else {
                    if ($this->isGifImagesEnabled !== false && $mime == "image/gif") {
                        /*
                        $image_o = imagecreatefromstring($image);
                        $image_p = imagecreatetruecolor($width * $ratio, $height * $ratio);

                        imagealphablending($image_p, false);
                        imagesavealpha($image_p, true);
                        imagealphablending($image_o, true);

                        imagecopyresampled($image_p, $image_o, 0, 0, 0, 0, ($width * $ratio), ($height * $ratio), $width, $height);
                        ob_start();
                        imagegif($image_p, null);
                        $image = ob_get_contents();
                        ob_end_clean();

                        imagedestroy($image_o);
                        imagedestroy($image_p);

                        $ext = "gif";
                        */

                        $tFileD = tempnam("BewareOfGeeksBearingGifs", "grD");

                        ResizeGif::ResizeByRatio($source, $tFileD, $ratio);
                        $image = file_get_contents($tFileD);
                        unlink($tFileD);
                    } else {
                        $image_o = imagecreatefromstring($image);
                        $image_p = imagecreatetruecolor($width * $ratio, $height * $ratio);

                        imagecopyresampled($image_p, $image_o, 0, 0, 0, 0, ($width * $ratio), ($height * $ratio), $width, $height);
                        ob_start();
                        imagejpeg($image_p, null, 80);
                        $image = ob_get_contents();
                        ob_end_clean();

                        imagedestroy($image_o);
                        imagedestroy($image_p);

                        $mime = "image/jpeg";
                        $ext = "jpg";
                    }
                }
            }
        }

        if ($ext === "") {
            static $mimeToExt = array(
                'image/jpeg' => 'jpg',
                'image/gif'  => 'gif',
                'image/png'  => 'png'
            );

            if (isset($mimeToExt[$mime])) {
                $ext = $mimeToExt[$mime];
            }
        }

        $rv = array();
        $rv['width'] = $width * $ratio;
        $rv['height'] = $height * $ratio;
        $rv['mime'] = $mime;
        $rv['image'] = $image;
        $rv['ext'] = $ext;

        return $rv;
    }

    /**
     * Get file contents, using curl if available, else file_get_contents
     *
     * @param string $source
     * @param bool   $toTempFile
     *
     * @return bool|mixed|null|string
     */
    function getFileContents($source, $toTempFile = false) {
        $isExternal = preg_match('#^(http|ftp)s?://#i', $source) == 1;

        if ($isExternal && $this->isCurlInstalled) {
            $ch = curl_init();
            $outFile = null;
            $fp = null;

            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_URL, str_replace(" ", "%20", $source));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 4096);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // follow redirects
            curl_setopt($ch, CURLOPT_ENCODING, ""); // handle all encodings
            curl_setopt($ch, CURLOPT_USERAGENT, "EPub (Version " . self::VERSION . ") by A. Grandt"); // who am i
            curl_setopt($ch, CURLOPT_AUTOREFERER, true); // set referer on redirect
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120); // timeout on connect
            curl_setopt($ch, CURLOPT_TIMEOUT, 120); // timeout on response
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10); // stop after 10 redirects
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disabled SSL Cert checks

            if ($toTempFile) {
                $outFile = tempnam(sys_get_temp_dir(), "EPub_v" . EPub::VERSION . "_");
                $fp = fopen($outFile, "w+b");
                curl_setopt($ch, CURLOPT_FILE, $fp);

                $res = curl_exec($ch);
                $info = curl_getinfo($ch);

                curl_close($ch);
                fclose($fp);
            } else {
                $res = curl_exec($ch);
                $info = curl_getinfo($ch);

                curl_close($ch);
            }

            if ($info['http_code'] == 200 && $res != false) {
                if ($toTempFile) {
                    return $outFile;
                }

                return $res;
            }

            return false;
        }

        if ($this->isFileGetContentsInstalled && (!$isExternal || $this->isFileGetContentsExtInstalled)) {
            @$data = file_get_contents($source);

            return $data;
        }

        return false;
    }

    /**
     * get mime type from image data
     *
     * By fireweasel found on http://stackoverflow.com/questions/2207095/get-image-mimetype-from-resource-in-php-gd
     *
     * @staticvar array $type
     *
     * @param object $binary
     *
     * @return string
     */
    function image_file_type_from_binary($binary) {
        $hits = null;
        if (!preg_match(
            '/\A(?:(\xff\xd8\xff)|(GIF8[79]a)|(\x89PNG\x0d\x0a)|(BM)|(\x49\x49(?:\x2a\x00|\x00\x4a))|(FORM.{4}ILBM))/',
            $binary, $hits)
        ) {
            return 'application/octet-stream';
        }
        static $type = array(
            1 => 'image/jpeg',
            2 => 'image/gif',
            3 => 'image/png',
            4 => 'image/x-windows-bmp',
            5 => 'image/tiff',
            6 => 'image/x-ilbm'
        );

        return $type[count($hits) - 1];
    }

    /**
     * @param string $source URL Source
     *
     * @return string MimeType
     */
    function getMimeTypeFromUrl($source) {
        $ext = false;

        $srev = strrev($source);
        $pos = strpos($srev, "?");
        if ($pos !== false) {
            $srev = substr($srev, $pos + 1);
        }

        $pos2 = strpos($srev, ".");
        if ($pos2 !== false) {
            $ext = strtolower(strrev(substr($srev, 0, $pos2)));
        }

        if ($ext !== false) {
            return $this->getMimeTypeFromExtension($ext);
        }

        return "application/octet-stream";
    }

    /**
     * @param string $ext Extension
     *
     * @return string MimeType
     */
    function getMimeTypeFromExtension($ext) {
        if (array_key_exists($ext, StaticData::$mimetypes)) {
            return StaticData::$mimetypes[$ext];
        }

        return "application/octet-stream";
    }

    /**
     * Add a file to the META-INF directory.
     * Bloody Apple and their bloody proprietary ways of doing things.
     *
     * @param string $fileName Filename to use for the file, must be unique for the book.
     * @param string $fileData File data
     *
     * @return bool $success
     */
    function addFileToMETAINF($fileName, $fileData) {
        if ($this->isFinalized) {
            return false;
        }
        if (!$this->isInitialized) {
            $this->initialize();
        }
        $fileName = $this->normalizeFileName($fileName);

        $this->zip->addFile($fileData, "META-INF/" . $fileName);

        return true;
    }

    /**
     * Add dynamically generated data as a file to the book.
     *
     * @param string $fileName Filename to use for the file, must be unique for the book.
     * @param string $fileId   Unique identifier for the file.
     * @param string $fileData File data
     * @param string $mimetype file mime type
     *
     * @return bool $success
     */
    function addFile($fileName, $fileId, $fileData, $mimetype) {
        if ($this->isFinalized || array_key_exists($fileName, $this->fileList)) {
            return false;
        }
        if (!$this->isInitialized) {
            $this->initialize();
        }
        $fileName = $this->normalizeFileName($fileName);

        $compress = (strpos($mimetype, "image/") !== 0);

        $this->zip->addFile($fileData, $this->bookRoot . $fileName, 0, null, $compress);
        $this->fileList[$fileName] = $fileName;
        $this->opf->addItem($fileId, $fileName, $mimetype);

        return true;
    }

    /**
     * Process link tags in a DOMDocument. Linked files will be loaded into the archive, and the link src will be rewritten to point to that location.
     * Link types text/css will be passed as CSS files.
     *
     * @param DOMDocument &$xmlDoc            (referenced)
     * @param int         $externalReferences How to handle external references, EPub::EXTERNAL_REF_IGNORE, EPub::EXTERNAL_REF_ADD or EPub::EXTERNAL_REF_REMOVE_IMAGES? Default is EPub::EXTERNAL_REF_ADD.
     * @param string      $baseDir            Default is "", meaning it is pointing to the document root.
     * @param string      $htmlDir            The path to the parent HTML file's directory from the root of the archive.
     * @param string      $backPath           The path to get back to the root of the archive from $htmlDir.
     *
     * @return bool  FALSE if uncuccessful (book is finalized or $externalReferences == EXTERNAL_REF_IGNORE).
     */
    protected function processChapterLinks(&$xmlDoc, $externalReferences = EPub::EXTERNAL_REF_ADD, $baseDir = "", $htmlDir = "", $backPath = "") {
        if ($this->isFinalized || $externalReferences === EPub::EXTERNAL_REF_IGNORE) {
            return false;
        }
        // process link tags.
        $links = $xmlDoc->getElementsByTagName("link");
        $linkCount = $links->length;
        for ($linkIdx = 0; $linkIdx < $linkCount; $linkIdx++) {
            /** @var $link \DOMElement */
            $link = $links->item($linkIdx);
            $source = $link->attributes->getNamedItem("href")->nodeValue;
            $sourceData = null;

            $pathData = pathinfo($source);
            $internalSrc = $pathData['basename'];

            if (preg_match('#^(http|ftp)s?://#i', $source) == 1) {
                $urlinfo = parse_url($source);

                if (strpos($urlinfo['path'], $baseDir . "/") !== false) {
                    $internalSrc = substr($urlinfo['path'], strpos($urlinfo['path'], $baseDir . "/") + strlen($baseDir) + 1);
                }

                @$sourceData = $this->getFileContents($source);
            } else {
                if (strpos($source, "/") === 0) {
                    @$sourceData = file_get_contents($this->docRoot . $source);
                } else {
                    @$sourceData = file_get_contents($this->docRoot . $baseDir . "/" . $source);
                }
            }

            if (!empty($sourceData)) {
                if (!array_key_exists($internalSrc, $this->fileList)) {
                    $mime = $link->attributes->getNamedItem("type")->nodeValue;
                    if (empty($mime)) {
                        $mime = "text/plain";
                    }
                    if ($mime == "text/css") {
                        $this->processCSSExternalReferences($sourceData, $externalReferences, $baseDir, $htmlDir);
                        $this->addCSSFile($internalSrc, $internalSrc, $sourceData, EPub::EXTERNAL_REF_IGNORE, $baseDir);
                        $link->setAttribute("href", $backPath . $internalSrc);
                    } else {
                        $this->addFile($internalSrc, $internalSrc, $sourceData, $mime);
                    }
                    $this->fileList[$internalSrc] = $source;
                } else {
                    $link->setAttribute("href", $backPath . $internalSrc);
                }
            } // else do nothing, if the link is local, and missing, assume it's been generated.
        }

        return true;
    }

    /**
     * Add a CSS file to the book.
     *
     * @param string $fileName           Filename to use for the CSS file, must be unique for the book.
     * @param string $fileId             Unique identifier for the file.
     * @param string $fileData           CSS data
     * @param int    $externalReferences How to handle external references, EPub::EXTERNAL_REF_IGNORE, EPub::EXTERNAL_REF_ADD or EPub::EXTERNAL_REF_REMOVE_IMAGES? See documentation for <code>processCSSExternalReferences</code> for explanation. Default is EPub::EXTERNAL_REF_IGNORE.
     * @param string $baseDir            Default is "", meaning it is pointing to the document root. NOT used if $externalReferences is set to EPub::EXTERNAL_REF_IGNORE.
     *
     * @return bool $success
     */
    function addCSSFile($fileName, $fileId, $fileData, $externalReferences = EPub::EXTERNAL_REF_IGNORE, $baseDir = "") {
        if ($this->isFinalized || array_key_exists($fileName, $this->fileList)) {
            return false;
        }
        $fileName = RelativePath::getRelativePath($fileName);
        $fileName = preg_replace('#^[/\.]+#i', "", $fileName);

        if ($externalReferences !== EPub::EXTERNAL_REF_IGNORE) {
            $cssDir = pathinfo($fileName);
            $cssDir = preg_replace('#^[/\.]+#i', "", $cssDir["dirname"] . "/");
            if (!empty($cssDir)) {
                $cssDir = preg_replace('#[^/]+/#i', "../", $cssDir);
            }

            $this->processCSSExternalReferences($fileData, $externalReferences, $baseDir, $cssDir);
        }

        $this->addFile($fileName, "css_" . $fileId, $fileData, "text/css");

        return true;
    }

    /**
     * Process img tags in a DOMDocument.
     * $externalReferences will determine what will happen to these images, and the img src will be rewritten accordingly.
     *
     * @param DOMDocument &$xmlDoc            (referenced)
     * @param int         $externalReferences How to handle external references, EPub::EXTERNAL_REF_IGNORE, EPub::EXTERNAL_REF_ADD or EPub::EXTERNAL_REF_REMOVE_IMAGES? Default is EPub::EXTERNAL_REF_ADD.
     * @param string      $baseDir            Default is "", meaning it is pointing to the document root.
     * @param string      $htmlDir            The path to the parent HTML file's directory from the root of the archive.
     * @param string      $backPath           The path to get back to the root of the archive from $htmlDir.
     *
     * @return bool  FALSE if uncuccessful (book is finalized or $externalReferences == EXTERNAL_REF_IGNORE).
     */
    protected function processChapterImages(&$xmlDoc, $externalReferences = EPub::EXTERNAL_REF_ADD, $baseDir = "", $htmlDir = "", $backPath = "") {
        if ($this->isFinalized || $externalReferences === EPub::EXTERNAL_REF_IGNORE) {
            return false;
        }
        // process img tags.
        $postProcDomElememts = array();
        $images = $xmlDoc->getElementsByTagName("img");
        $itemCount = $images->length;

        for ($idx = 0; $idx < $itemCount; $idx++) {
            /** @var $img \DOMElement */
            $img = $images->item($idx);

            if ($externalReferences === EPub::EXTERNAL_REF_REMOVE_IMAGES) {
                $postProcDomElememts[] = $img;
            } else {
                if ($externalReferences === EPub::EXTERNAL_REF_REPLACE_IMAGES) {
                    $altNode = $img->attributes->getNamedItem("alt");
                    $alt = "image";
                    if ($altNode !== null && strlen($altNode->nodeValue) > 0) {
                        $alt = $altNode->nodeValue;
                    }
                    $postProcDomElememts[] = array(
                        $img,
                        $this->createDomFragment($xmlDoc, "<em>[" . $alt . "]</em>")
                    );
                } else {
                    $source = $img->attributes->getNamedItem("src")->nodeValue;

                    $parsedSource = parse_url($source);
                    $internalSrc = $this->sanitizeFileName(urldecode(pathinfo($parsedSource['path'], PATHINFO_BASENAME)));
                    $internalPath = "";
                    $isSourceExternal = false;

                    if ($this->resolveImage($source, $internalPath, $internalSrc, $isSourceExternal, $baseDir, $htmlDir)) {
                        $img->setAttribute("src", $backPath . $internalPath);
                    } else {
                        if ($isSourceExternal) {
                            $postProcDomElememts[] = $img; // External image is missing
                        }
                    } // else do nothing, if the image is local, and missing, assume it's been generated.
                }
            }
        }

        foreach ($postProcDomElememts as $target) {
            if (is_array($target)) {
                $target[0]->parentNode->replaceChild($target[1], $target[0]);
            } else {
                $target->parentNode->removeChild($target);
            }
        }

        return true;
    }

    /**
     * Helper function to create a DOM fragment with given markup.
     *
     * @author Adam Schmalhofer
     *
     * @param DOMDocument $dom
     * @param string      $markup
     *
     * @return DOMNode fragment in a node.
     */
    protected function createDomFragment($dom, $markup) {
        $node = $dom->createDocumentFragment();
        $node->appendXML($markup);

        return $node;
    }

    /**
     * Process source tags in a DOMDocument.
     * $externalReferences will determine what will happen to these images, and the img src will be rewritten accordingly.
     *
     * @param DOMDocument &$xmlDoc            (referenced)
     * @param int         $externalReferences How to handle external references, EPub::EXTERNAL_REF_IGNORE, EPub::EXTERNAL_REF_ADD or EPub::EXTERNAL_REF_REMOVE_IMAGES? Default is EPub::EXTERNAL_REF_ADD.
     * @param string      $baseDir            Default is "", meaning it is pointing to the document root.
     * @param string      $htmlDir            The path to the parent HTML file's directory from the root of the archive.
     * @param string      $backPath           The path to get back to the root of the archive from $htmlDir.
     *
     * @return bool  FALSE if uncuccessful (book is finalized or $externalReferences == EXTERNAL_REF_IGNORE).
     */
    protected function processChapterSources(&$xmlDoc, $externalReferences = EPub::EXTERNAL_REF_ADD, $baseDir = "", $htmlDir = "", $backPath = "") {
        if ($this->isFinalized || $externalReferences === EPub::EXTERNAL_REF_IGNORE) {
            return false;
        }

        if ($this->bookVersion !== EPub::BOOK_VERSION_EPUB3) {
            // ePub 2 does not support multimedia formats, and they must be removed.
            $externalReferences = EPub::EXTERNAL_REF_REMOVE_IMAGES;
        }

        $postProcDomElememts = array();
        $images = $xmlDoc->getElementsByTagName("source");
        $itemCount = $images->length;
        for ($idx = 0; $idx < $itemCount; $idx++) {
            /** @var $img \DOMElement */
            $img = $images->item($idx);
            if ($externalReferences === EPub::EXTERNAL_REF_REMOVE_IMAGES) {
                $postProcDomElememts[] = $img;
            } else {
                if ($externalReferences === EPub::EXTERNAL_REF_REPLACE_IMAGES) {
                    $altNode = $img->attributes->getNamedItem("alt");
                    $alt = "image";
                    if ($altNode !== null && strlen($altNode->nodeValue) > 0) {
                        $alt = $altNode->nodeValue;
                    }
                    $postProcDomElememts[] = array(
                        $img,
                        $this->createDomFragment($xmlDoc, "[" . $alt . "]")
                    );
                } else {
                    $source = $img->attributes->getNamedItem("src")->nodeValue;

                    $parsedSource = parse_url($source);
                    $internalSrc = $this->sanitizeFileName(urldecode(pathinfo($parsedSource['path'], PATHINFO_BASENAME)));
                    $internalPath = "";
                    $isSourceExternal = false;

                    if ($this->resolveMedia($source, $internalPath, $internalSrc, $isSourceExternal, $baseDir, $htmlDir)) {
                        $img->setAttribute("src", $backPath . $internalPath);
                    } else {
                        if ($isSourceExternal) {
                            $postProcDomElememts[] = $img; // External image is missing
                        }
                    } // else do nothing, if the image is local, and missing, assume it's been generated.
                }
            }
        }

        return true;
    }

    /**
     * Resolve a media src and determine it's target location and add it to the book.
     *
     * @param string $source           Source link.
     * @param string $internalPath     (referenced) Return value, will be set to the target path and name in the book.
     * @param string $internalSrc      (referenced) Return value, will be set to the target name in the book.
     * @param string $isSourceExternal (referenced) Return value, will be set to TRUE if the image originated from a full URL.
     * @param string $baseDir          Default is "", meaning it is pointing to the document root.
     * @param string $htmlDir          The path to the parent HTML file's directory from the root of the archive.
     *
     * @return bool
     */
    protected function resolveMedia($source, &$internalPath, &$internalSrc, &$isSourceExternal, $baseDir = "", $htmlDir = "") {
        if ($this->isFinalized) {
            return false;
        }
        $mediaPath = null;
        $tmpFile = null;

        if (preg_match('#^(http|ftp)s?://#i', $source) == 1) {
            $urlInfo = parse_url($source);

            if (strpos($urlInfo['path'], $baseDir . "/") !== false) {
                $internalSrc = substr($urlInfo['path'], strpos($urlInfo['path'], $baseDir . "/") + strlen($baseDir) + 1);
            }
            $internalPath = $urlInfo["scheme"] . "/" . $urlInfo["host"] . "/" . pathinfo($urlInfo["path"], PATHINFO_DIRNAME);
            $isSourceExternal = true;
            $mediaPath = $this->getFileContents($source, true);
            $tmpFile = $mediaPath;
        } else {
            if (strpos($source, "/") === 0) {
                $internalPath = pathinfo($source, PATHINFO_DIRNAME);

                $mediaPath = $source;
                if (!file_exists($mediaPath)) {
                    $mediaPath = $this->docRoot . $mediaPath;
                }
            } else {
                $internalPath = $htmlDir . "/" . preg_replace('#^[/\.]+#', '', pathinfo($source, PATHINFO_DIRNAME));

                $mediaPath = $baseDir . "/" . $source;
                if (!file_exists($mediaPath)) {
                    $mediaPath = $this->docRoot . $mediaPath;
                }
            }
        }

        if ($mediaPath !== false) {
            $mime = $this->getMimeTypeFromExtension(pathinfo($source, PATHINFO_EXTENSION));
            $internalPath = RelativePath::getRelativePath("media/" . $internalPath . "/" . $internalSrc);

            if (!array_key_exists($internalPath, $this->fileList) &&
                $this->addLargeFile($internalPath, "m_" . $internalSrc, $mediaPath, $mime)
            ) {
                $this->fileList[$internalPath] = $source;
            }
            if (isset($tmpFile)) {
                unlink($tmpFile);
            }

            return true;
        }

        return false;
    }

    /**
     * Add a large file directly from the filestystem to the book.
     *
     * @param string $fileName Filename to use for the file, must be unique for the book.
     * @param string $fileId   Unique identifier for the file.
     * @param string $filePath File path
     * @param string $mimetype file mime type
     *
     * @return bool $success
     */
    function addLargeFile($fileName, $fileId, $filePath, $mimetype) {
        if ($this->isFinalized || array_key_exists($fileName, $this->fileList)) {
            return false;
        }
        if (!$this->isInitialized) {
            $this->initialize();
        }
        $fileName = $this->normalizeFileName($fileName);

        if ($this->zip->addLargeFile($filePath, $this->bookRoot . $fileName)) {
            $this->fileList[$fileName] = $fileName;
            $this->opf->addItem($fileId, $fileName, $mimetype);

            return true;
        }

        return false;
    }

    /**
     * initialize defaults.
     */
    private function initialize() {
        if ($this->isInitialized) {
            return;
        }
        if (strlen($this->bookRoot) != 0 && $this->bookRoot != 'OEBPS/') {
            $this->setBookRoot($this->bookRoot);
        }

        $this->isInitialized = true;

        // GIF Resize requires the GD extension to be available.
        if ($this->isGdInstalled && $this->isGifImagesEnabled
            && file_exists($this->pluginDir . '/GIFEncoder.class.php')
        ) {
            $this->isAnimatedGifResizeInstalled = class_exists('GIFDecoder') && class_exists('GIFEncoder');
        }

        if (!$this->isEPubVersion2()) {
            $this->htmlContentHeader = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
                . "<html xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:epub=\"http://www.idpf.org/2007/ops\">\n"
                . "<head>"
                . "<meta http-equiv=\"Default-Style\" content=\"text/html; charset=utf-8\" />\n"
                . "<title></title>\n"
                . "</head>\n"
                . "<body>\n";
        }

        $content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<container version=\"1.0\" xmlns=\"urn:oasis:names:tc:opendocument:xmlns:container\">\n\t<rootfiles>\n\t\t<rootfile full-path=\"" . $this->bookRoot . "book.opf\" media-type=\"application/oebps-package+xml\" />\n\t</rootfiles>\n</container>\n";

        $this->zip->addFile($content, "META-INF/container.xml", 0, null, false);
        $this->ncx->setVersion($this->bookVersion);
        $this->opf->setVersion($this->bookVersion);
        $this->opf->addItem("ncx", "book.ncx", Ncx::MIMETYPE);
        $this->chapterCount = 0;

        $this->ncx->setLanguageCode($this->languageCode);
        $this->ncx->setWritingDirection($this->writingDirection);
    }

    /**
     * The bookRoot is the root directory inside the ePub book, defaults to "OEBPS/"
     *
     * @param string $bookRoot
     */
    function setBookRoot($bookRoot) {
        if ($this->isInitialized) {
            die("bookRoot can't be set after book initialization (first file added).");
        }
        $bookRoot = trim($bookRoot);
        if (strlen($bookRoot) <= 1 || $bookRoot == '/') {
            $bookRoot = '';
        } else {
            if (!$this->endsWith($bookRoot, '/')) {
                $bookRoot .= '/';
            }
        }
        $this->bookRoot = $bookRoot;
    }

    /**
     * Implementation of Java String's endWidth method.
     *
     * @param string $haystack
     * @param string $needle
     *
     * @return bool true or false.
     */
    function endsWith($haystack, $needle) {
        return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
    }

    /**
     * @return bool
     */
    function isEPubVersion2() {
        return $this->bookVersion === EPub::BOOK_VERSION_EPUB2;
    }

    /**
     * Cleanup the filepath, and remove leading . and / characters.
     *
     * Sometimes, when a path is generated from multiple fragments,
     *  you can get something like "../data/html/../images/image.jpeg"
     * ePub files don't work well with that, this will normalize that
     *  example path to "data/images/image.jpeg"
     *
     * @param string $fileName
     *
     * @return string normalized filename
     */
    function normalizeFileName($fileName) {
        return preg_replace('#^[/\.]+#i', "", RelativePath::getRelativePath($fileName));
    }

    /**
     * Encode html code to use html entities, safeguarding it from potential character encoding peoblems
     * This function is a bit different from the vanilla htmlentities function in that it does not encode html tags.
     *
     * The regexp is taken from the PHP Manual discussion, it was written by user "busbyjon".
     * http://www.php.net/manual/en/function.htmlentities.php#90111
     *
     * @param string $string string to encode.
     *
     * @return string
     */
    public function encodeHtml($string) {
        $string = strtr($string, StaticData::$htmlEntities);

        return $string;
    }

    /**
     * Remove all non essential html tags and entities.
     *
     * @param string $string
     *
     * @return string with the stripped entities.
     */
    function decodeHtmlEntities($string) {
        $string = preg_replace('~\s*<br\s*/*\s*>\s*~i', "\n", $string);
        $string = preg_replace('~\s*</(p|div)\s*>\s*~i', "\n\n", $string);
        $string = preg_replace('~<[^>]*>~', '', $string);

        $string = strtr($string, StaticData::$htmlEntities);

        $string = str_replace('&', '&amp;', $string);
        $string = str_replace('&amp;amp;', '&amp;', $string);
        $string = preg_replace('~&amp;(#x*[a-fA-F0-9]+;)~', '&\1', $string);
        $string = str_replace('<', '&lt;', $string);
        $string = str_replace('>', '&gt;', $string);

        return $string;
    }

    /**
     * Add one chapter level.
     *
     * Subsequent chapters will be added to this level.
     *
     * @param string $navTitle
     * @param string $navId
     * @param string $navClass
     * @param bool   $isNavHidden
     * @param string $writingDirection
     *
     * @return bool|NavPoint The new NavPoint for that level.
     */
    function subLevel($navTitle = null, $navId = null, $navClass = null, $isNavHidden = false, $writingDirection = null) {
        return $this->ncx->subLevel($this->decodeHtmlEntities($navTitle), $navId, $navClass, $isNavHidden, $writingDirection);
    }

    /**
     * Step back one chapter level.
     *
     * Subsequent chapters will be added to this chapters parent level.
     */
    function backLevel() {
        $this->ncx->backLevel();
    }

    /**
     * Step back to the root level.
     *
     * Subsequent chapters will be added to the rooot NavMap.
     */
    function rootLevel() {
        $this->ncx->rootLevel();
    }

    /**
     * Step back to the given level.
     * Useful for returning to a previous level from deep within the structure.
     * Values below 2 will have the same effect as rootLevel()
     *
     * @param int $newLevel
     */
    function setCurrentLevel($newLevel) {
        $this->ncx->setCurrentLevel($newLevel);
    }

    /**
     * Get current level count.
     * The indentation of the current structure point.
     *
     * @return int current level count;
     */
    function getCurrentLevel() {
        return $this->ncx->getCurrentLevel();
    }

    /**
     * Add custom metadata to the book.
     *
     * It is up to the builder to make sure there are no collisions. Metadata are just key value pairs.
     *
     * @param string $name
     * @param string $content
     */
    function addCustomMetadata($name, $content) {
        $this->opf->addMeta($name, $content);
    }

    /**
     * Add DublinCore metadata to the book
     *
     * Use the DublinCore constants included in EPub, ie DublinCore::DATE
     *
     * @param string $dublinCoreConstant name
     * @param string $value
     */
    function addDublinCoreMetadata($dublinCoreConstant, $value) {
        if ($this->isFinalized) {
            return;
        }

        $this->opf->addDCMeta($dublinCoreConstant, $this->decodeHtmlEntities($value));
    }

    /**
     * Add a cover image to the book.
     * If the $imageData is not set, the function assumes the $fileName is the path to the image file.
     *
     * The styling and structure of the generated XHTML is heavily inspired by the XHTML generated by Calibre.
     *
     * @param string $fileName  Filename to use for the image, must be unique for the book.
     * @param string $imageData Binary image data
     * @param string $mimetype  Image mimetype, such as "image/jpeg" or "image/png".
     *
     * @return bool $success
     */
    function setCoverImage($fileName, $imageData = null, $mimetype = null) {
        if ($this->isFinalized || $this->isCoverImageSet || array_key_exists("CoverPage.html", $this->fileList)) {
            return false;
        }

        if ($imageData == null) {
            // assume $fileName is the valid file path.
            if (!file_exists($fileName)) {
                // Attempt to locate the file using the doc root.
                $rp = realpath($this->docRoot . "/" . $fileName);

                if ($rp !== false) {
                    // only assign the docroot path if it actually exists there.
                    $fileName = $rp;
                }
            }
            $image = $this->getImage($fileName);
            $imageData = $image['image'];
            $mimetype = $image['mime'];
            $fileName = preg_replace('#\.[^\.]+$#', "." . $image['ext'], $fileName);
        }

        $path = pathinfo($fileName);
        $imgPath = "images/" . $path["basename"];

        if (empty($mimetype) && file_exists($fileName)) {
            /** @noinspection PhpUnusedLocalVariableInspection */
            list($width, $height, $type, $attr) = getimagesize($fileName);
            $mimetype = image_type_to_mime_type($type);
        }
        if (empty($mimetype)) {
            $ext = strtolower($path['extension']);
            if ($ext == "jpg") {
                $ext = "jpeg";
            }
            $mimetype = "image/" . $ext;
        }

        if ($this->isEPubVersion2()) {
            $coverPage = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
                . "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\"\n"
                . "  \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">\n"
                . "<html xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:epub=\"http://www.idpf.org/2007/ops\" xml:lang=\"en\">\n"
                . "\t<head>\n"
                . "\t\t<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"/>\n"
                . "\t\t<title>Cover Image</title>\n"
                . "\t\t<link type=\"text/css\" rel=\"stylesheet\" href=\"Styles/CoverPage.css\" />\n"
                . "\t</head>\n"
                . "\t<body>\n"
                . "\t\t<div>\n"
                . "\t\t\t<img src=\"" . $imgPath . "\" alt=\"Cover image\" style=\"height: 100%\"/>\n"
                . "\t\t</div>\n"
                . "\t</body>\n"
                . "</html>\n";
        } else {
            $coverPage = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
                . "<html xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:epub=\"http://www.idpf.org/2007/ops\">\n"
                . "<head>"
                . "\t<meta http-equiv=\"Default-Style\" content=\"text/html; charset=utf-8\" />\n"
                . "\t\t<title>Cover Image</title>\n"
                . "\t\t<link type=\"text/css\" rel=\"stylesheet\" href=\"Styles/CoverPage.css\" />\n"
                . "\t</head>\n"
                . "\t<body>\n"
                . "\t\t<section epub:type=\"cover\">\n" . "\t\t\t<img src=\"" . $imgPath . "\" alt=\"Cover image\" style=\"height: 100%\"/>\n"
                . "\t\t</section>\n"
                . "\t</body>\n"
                . "</html>\n";
        }
        $coverPageCss = "@page, body, div, img {\n"
            . "\tpadding: 0pt;\n"
            . "\tmargin:0pt;\n"
            . "}\n\nbody {\n"
            . "\ttext-align: center;\n"
            . "}\n";

        $this->addCSSFile("Styles/CoverPage.css", "CoverPageCss", $coverPageCss);
        $this->addFile($imgPath, "CoverImage", $imageData, $mimetype);
        $this->addReferencePage("CoverPage", "CoverPage.xhtml", $coverPage, "cover");
        $this->isCoverImageSet = true;

        return true;
    }

    /**
     * Reference pages is usually one or two pages for items such as Table of Contents, reference lists, Author notes or Acknowledgements.
     * These do not show up in the regular navigation list.
     *
     * As they are supposed to be short.
     *
     * @param string $pageName           Name of the chapter, will be use din the TOC
     * @param string $fileName           Filename to use for the chapter, must be unique for the book.
     * @param string $pageData           Page content in XHTML. File should NOT exceed 250kB.
     * @param string $reference          Reference key
     * @param int    $externalReferences How to handle external references. See documentation for <code>processChapterExternalReferences</code> for explanation. Default is EPub::EXTERNAL_REF_IGNORE.
     * @param string $baseDir            Default is "", meaning it is pointing to the document root. NOT used if $externalReferences is set to EPub::EXTERNAL_REF_IGNORE.
     *
     * @return bool $success
     */
    function addReferencePage($pageName, $fileName, $pageData, $reference, $externalReferences = EPub::EXTERNAL_REF_IGNORE, $baseDir = "") {
        if ($this->isFinalized) {
            return false;
        }
        $fileName = RelativePath::getRelativePath($fileName);
        $fileName = preg_replace('#^[/\.]+#i', "", $fileName);

        if (!empty($pageData) && is_string($pageData)) {
            if ($this->encodeHTML === true) {
                $pageData = $this->encodeHtml($pageData);
            }

            $this->wrapChapter($pageData);

            if ($externalReferences !== EPub::EXTERNAL_REF_IGNORE) {
                $htmlDirInfo = pathinfo($fileName);
                $htmlDir = preg_replace('#^[/\.]+#i', "", $htmlDirInfo["dirname"] . "/");
                $this->processChapterExternalReferences($pageData, $externalReferences, $baseDir, $htmlDir);
            }

            $this->addFile($fileName, "ref_" . $reference, $pageData, "application/xhtml+xml");

            if ($reference !== Reference::TABLE_OF_CONTENTS || !isset($this->ncx->referencesList[$reference])) {
                $this->opf->addItemRef("ref_" . $reference, false);
                $this->opf->addReference($reference, $pageName, $fileName);

                $this->ncx->referencesList[$reference] = $fileName;
                $this->ncx->referencesName[$reference] = $pageName;
            }

            return true;
        }

        return true;
    }

    /**
     * Wrap ChapterContent with Head and Footer
     *
     * @param $content
     *
     * @return string $content
     */
    private function wrapChapter($content) {
        return $this->htmlContentHeader . "\n" . $content . "\n" . $this->htmlContentFooter;
    }

    /**
     * Get Book Chapter count.
     *
     * @access public
     * @return number of chapters
     */
    function getChapterCount() {
        return $this->chapterCount;
    }

    /**
     * Get Book title.
     *
     * @access public
     * @return string $title
     */
    function getTitle() {
        return $this->title;
    }

    /**
     * Book title, mandatory.
     *
     * Used for the dc:title metadata parameter in the OPF file as well as the DocTitle attribute in the NCX file.
     *
     * @param string $title
     *
     * @access public
     * @return bool $success
     */
    function setTitle($title) {
        if ($this->isFinalized) {
            return false;
        }
        $this->title = $title;

        return true;
    }

    /**
     * Get Book language.
     *
     * @access public
     * @return string $language
     */
    function getLanguage() {
        return $this->language;
    }

    /**
     * Book language, mandatory
     *
     * Use the RFC3066 Language codes, such as "en", "da", "fr" etc.
     * Defaults to "en".
     *
     * Used for the dc:language metadata parameter in the OPF file.
     *
     * @param string $language
     *
     * @access public
     * @return bool $success
     */
    function setLanguage($language) {
        if ($this->isFinalized || mb_strlen($language) != 2) {
            return false;
        }
        $this->language = $language;

        return true;
    }

    /**
     * Get Book identifier.
     *
     * @access public
     * @return string $identifier
     */
    function getIdentifier() {
        return $this->identifier;
    }

    /**
     * Unique book identifier, mandatory.
     * Use the URI, or ISBN if available.
     *
     * An unambiguous reference to the resource within a given context.
     *
     * Recommended best practice is to identify the resource by means of a
     *  string conforming to a formal identification system.
     *
     * Used for the dc:identifier metadata parameter in the OPF file, as well
     *  as dtb:uid in the NCX file.
     *
     * Identifier type should only be:
     *  EPub::IDENTIFIER_URI
     *  EPub::IDENTIFIER_ISBN
     *  EPub::IDENTIFIER_UUID
     *
     * @param string $identifier
     * @param string $identifierType
     *
     * @access public
     * @return bool $success
     */
    function setIdentifier($identifier, $identifierType) {
        if ($this->isFinalized || ($identifierType !== EPub::IDENTIFIER_URI && $identifierType !== EPub::IDENTIFIER_ISBN && $identifierType !== EPub::IDENTIFIER_UUID)) {
            return false;
        }
        $this->identifier = $identifier;
        $this->identifierType = $identifierType;

        return true;
    }

    /**
     * Get Book identifierType.
     *
     * @access public
     * @return string $identifierType
     */
    function getIdentifierType() {
        return $this->identifierType;
    }

    /**
     * Get Book description.
     *
     * @access public
     * @return string $description
     */
    function getDescription() {
        return $this->description;
    }

    /**
     * Book description, optional.
     *
     * An account of the resource.
     *
     * Description may include but is not limited to: an abstract, a table of
     *  contents, a graphical representation, or a free-text account of the
     *  resource.
     *
     * Used for the dc:source metadata parameter in the OPF file
     *
     * @param string $description
     *
     * @access public
     * @return bool $success
     */
    function setDescription($description) {
        if ($this->isFinalized) {
            return false;
        }
        $this->description = $description;

        return true;
    }

    /**
     * Get Book author.
     *
     * @access public
     * @return string $author
     */
    function getAuthor() {
        return $this->author;
    }

    /**
     * Book author or creator, optional.
     * The $authorSortKey is basically how the name is to be sorted, usually
     *  it's "Lastname, First names" where the $author is the straight
     *  "Firstnames Lastname"
     *
     * An entity primarily responsible for making the resource.
     *
     * Examples of a Creator include a person, an organization, or a service.
     *  Typically, the name of a Creator should be used to indicate the entity.
     *
     * Used for the dc:creator metadata parameter in the OPF file and the
     *  docAuthor attribure in the NCX file.
     * The sort key is used for the opf:file-as attribute in dc:creator.
     *
     * @param string $author
     * @param string $authorSortKey
     *
     * @access public
     * @return bool $success
     */
    function setAuthor($author, $authorSortKey) {
        if ($this->isFinalized) {
            return false;
        }
        $this->author = $author;
        $this->authorSortKey = $authorSortKey;

        return true;
    }

    /**
     * Publisher Information, optional.
     *
     * An entity responsible for making the resource available.
     *
     * Examples of a Publisher include a person, an organization, or a service.
     *  Typically, the name of a Publisher should be used to indicate the entity.
     *
     * Used for the dc:publisher and dc:relation metadata parameters in the OPF file.
     *
     * @param string $publisherName
     * @param string $publisherURL
     *
     * @access public
     * @return bool $success
     */
    function setPublisher($publisherName, $publisherURL) {
        if ($this->isFinalized) {
            return false;
        }
        $this->publisherName = $publisherName;
        $this->publisherURL = $publisherURL;

        return true;
    }

    /**
     * Get Book publisherName.
     *
     * @access public
     * @return string $publisherName
     */
    function getPublisherName() {
        return $this->publisherName;
    }

    /**
     * Get Book publisherURL.
     *
     * @access public
     * @return string $publisherURL
     */
    function getPublisherURL() {
        return $this->publisherURL;
    }

    /**
     * Get Book date.
     *
     * @access public
     * @return string $date
     */
    function getDate() {
        return $this->date;
    }

    /**
     * Release date, optional. If left blank, the time of the finalization will
     *  be used.
     *
     * A point or period of time associated with an event in the lifecycle of
     *  the resource.
     *
     * Date may be used to express temporal information at any level of
     *  granularity.  Recommended best practice is to use an encoding scheme,
     *  such as the W3CDTF profile of ISO 8601 [W3CDTF].
     *
     * Used for the dc:date metadata parameter in the OPF file
     *
     * @param int $timestamp
     *
     * @access public
     * @return bool $success
     */
    function setDate($timestamp) {
        if ($this->isFinalized) {
            return false;
        }
        $this->date = $timestamp;
        $this->opf->date = $timestamp;

        return true;
    }

    /**
     * Get Book rights.
     *
     * @access public
     * @return string $rights
     */
    function getRights() {
        return $this->rights;
    }

    /**
     * Book (copy)rights, optional.
     *
     * Information about rights held in and over the resource.
     *
     * Typically, rights information includes a statement about various
     *  property rights associated with the resource, including intellectual
     *  property rights.
     *
     * Used for the dc:rights metadata parameter in the OPF file
     *
     * @param string $rightsText
     *
     * @access public
     * @return bool $success
     */
    function setRights($rightsText) {
        if ($this->isFinalized) {
            return false;
        }
        $this->rights = $rightsText;

        return true;
    }

    /**
     * Add book Subject.
     *
     * The topic of the resource.
     *
     * Typically, the subject will be represented using keywords, key phrases,
     *  or classification codes. Recommended best practice is to use a
     *  controlled vocabulary. To describe the spatial or temporal topic of the
     *  resource, use the Coverage element.
     *
     * @param string $subject
     */
    function setSubject($subject) {
        if ($this->isFinalized) {
            return;
        }
        $this->opf->addDCMeta(DublinCore::SUBJECT, $this->decodeHtmlEntities($subject));
    }

    /**
     * Get Book sourceURL.
     *
     * @access public
     * @return string $sourceURL
     */
    function getSourceURL() {
        return $this->sourceURL;
    }

    /**
     * Book source URL, optional.
     *
     * A related resource from which the described resource is derived.
     *
     * The described resource may be derived from the related resource in whole
     *  or in part. Recommended best practice is to identify the related
     *  resource by means of a string conforming to a formal identification system.
     *
     * Used for the dc:source metadata parameter in the OPF file
     *
     * @param string $sourceURL
     *
     * @access public
     * @return bool $success
     */
    function setSourceURL($sourceURL) {
        if ($this->isFinalized) {
            return false;
        }
        $this->sourceURL = $sourceURL;

        return true;
    }

    /**
     * Get Book coverage.
     *
     * @access public
     * @return string $coverage
     */
    function getCoverage() {
        return $this->coverage;
    }

    /**
     * Coverage, optional.
     *
     * The spatial or temporal topic of the resource, the spatial applicability
     *  of the resource, or the jurisdiction under which the resource is relevant.
     *
     * Spatial topic and spatial applicability may be a named place or a location
     *  specified by its geographic coordinates. Temporal topic may be a named
     *  period, date, or date range. A jurisdiction may be a named administrative
     *  entity or a geographic place to which the resource applies. Recommended
     *  best practice is to use a controlled vocabulary such as the Thesaurus of
     *  Geographic Names [TGN]. Where appropriate, named places or time periods
     *  can be used in preference to numeric identifiers such as sets of
     *  coordinates or date ranges.
     *
     * Used for the dc:coverage metadata parameter in the OPF file
     *
     * Same as ->addDublinCoreMetadata(DublinCore::COVERAGE, $coverage);
     *
     * @param string $coverage
     *
     * @access public
     * @return bool $success
     */
    function setCoverage($coverage) {
        if ($this->isFinalized) {
            return false;
        }
        $this->coverage = $coverage;

        return true;
    }

    /**
     * Get the book relation.
     *
     * @return string The relation.
     */
    function getRelation() {
        return $this->relation;
    }

    /**
     * Set book Relation.
     *
     * A related resource.
     *
     * Recommended best practice is to identify the related resource by means
     *  of a string conforming to a formal identification system.
     *
     * @param string $relation
     */
    function setRelation($relation) {
        if ($this->isFinalized) {
            return;
        }
        $this->relation = $relation;
    }

    /**
     * Get the book relation.
     *
     * @return string The generator identity string.
     */
    function getGenerator() {
        return $this->generator;
    }

    /**
     * Set book Generator.
     *
     * The generator is a meta tag added to the ncx file, it is not visible
     *  from within the book, but is a kind of electronic watermark.
     *
     * @param string $generator
     */
    function setGenerator($generator) {
        if ($this->isFinalized) {
            return;
        }
        $this->generator = $generator;
    }

    /**
     * Set ePub date formate to the short yyyy-mm-dd form, for compliance with
     *  a bug in EpubCheck, prior to its version 1.1.
     *
     * The latest version of ePubCheck can be obtained here:
     *  http://code.google.com/p/epubcheck/
     *
     * @access public
     * @return bool $success
     */
    function setShortDateFormat() {
        if ($this->isFinalized) {
            return false;
        }
        $this->dateformat = $this->dateformatShort;

        return true;
    }

    /**
     * Set the references title for the ePub 3 landmarks section
     *
     * @param string $referencesTitle
     * @param string $referencesId
     * @param string $referencesClass
     *
     * @return bool
     */
    function setReferencesTitle($referencesTitle = "Guide", $referencesId = "", $referencesClass = "references") {
        if ($this->isFinalized) {
            return false;
        }
        $this->ncx->referencesTitle = is_string($referencesTitle) ? trim($referencesTitle) : "Guide";
        $this->ncx->referencesId = is_string($referencesId) ? trim($referencesId) : "references";
        $this->ncx->referencesClass = is_string($referencesClass) ? trim($referencesClass) : "references";

        return true;
    }

    /**
     * Set the references title for the ePub 3 landmarks section
     *
     * @param bool $isReferencesAddedToToc
     *
     * @return bool
     */
    function setisReferencesAddedToToc($isReferencesAddedToToc = true) {
        if ($this->isFinalized) {
            return false;
        }
        $this->isReferencesAddedToToc = $isReferencesAddedToToc === true;

        return true;
    }

    /**
     * Get Book status.
     *
     * @access public
     * @return bool
     */
    function isFinalized() {
        return $this->isFinalized;
    }

    /**
     * Build the Table of Contents. This is not strictly necessary, as most eReaders will build it from the navigation structure in the .ncx file.
     *
     * @param string $cssFileName   Include a link to this css file in the TOC html.
     * @param string $tocCSSClass   The TOC is a <div>, if you need special formatting, you can add a css class for that div. Default is "toc".
     * @param string $title         Title of the Table of contents. Default is "Table of Contents". Use this for ie. languages other than English.
     * @param bool   $addReferences include reference pages in the TOC, using the $referencesOrder array to determine the order of the pages in the TOC. Default is TRUE.
     * @param bool   $addToIndex    Add the TOC to the NCX index at the current leve/position. Default is FALSE
     * @param string $tocFileName   Change the default name of the TOC file. The default is "TOC.xhtml"
     *
     * @return bool
     */
    function buildTOC($cssFileName = null, $tocCSSClass = "toc", $title = "Table of Contents", $addReferences = true, $addToIndex = false, $tocFileName = "TOC.xhtml") {
        if ($this->isFinalized) {
            return false;
        }
        $this->buildTOC = true;
        $this->tocTitle = $title;
        $this->tocFileName = $this->normalizeFileName($tocFileName);
        if (!empty($cssFileName)) {
            $this->tocCssFileName = $this->normalizeFileName($cssFileName);
        }
        $this->tocCSSClass = $tocCSSClass;
        $this->tocAddReferences = $addReferences;

        $this->opf->addReference(Reference::TABLE_OF_CONTENTS, $title, $this->tocFileName);
        if (!$this->tocNavAdded = true) {
            $this->opf->addItemRef("ref_" . Reference::TABLE_OF_CONTENTS, false);

            if ($addToIndex) {
                $navPoint = new NavPoint($this->decodeHtmlEntities($title), $this->tocFileName, "ref_" . Reference::TABLE_OF_CONTENTS);
                $this->ncx->addNavPoint($navPoint);
            } else {
                $this->ncx->referencesList[Reference::TABLE_OF_CONTENTS] = $this->tocFileName;
                $this->ncx->referencesName[Reference::TABLE_OF_CONTENTS] = $title;
            }
        }
        return true;
    }

    /**
     * Save the ePub file to local disk.
     *
     * @param string $fileName
     * @param string $baseDir If empty baseDir is absolute to server path, if omitted it's relative to script path
     *
     * @return string The sent file name if successful, FALSE if it failed.
     */
    function saveBook($fileName, $baseDir = '.') {

        // Make fileName safe
        // $fileName = $this->sanitizeFileName($fileName); // It is up to the user to ensure valid file names.

        // Finalize book, if it's not done already
        if (!$this->isFinalized) {
            $this->finalize();
        }

        if (!$this->endsWith($fileName, ".epub")) {
            $fileName .= ".epub";
        }

        // Try to open file access
        $fh = fopen($baseDir . '/' . $fileName, "w");

        if ($fh) {
            fputs($fh, $this->getBook());
            fclose($fh);

            // if file is written return TRUE
            return $fileName;
        }

        // return FALSE by default
        return false;
    }

    /**
     * Check for mandatory parameters and finalize the e-book.
     * Once finalized, the book is locked for further additions.
     *
     * @return bool $success
     */
    function finalize() {
        if ($this->isFinalized || $this->chapterCount == 0 || empty($this->title) || empty($this->language)) {
            return false;
        }

        if (empty($this->identifier) || empty($this->identifierType)) {
            $this->setIdentifier($this->createUUID(4), EPub::IDENTIFIER_UUID);
        }

        if ($this->date == 0) {
            $this->date = time();
        }

        if (empty($this->sourceURL)) {
            $this->sourceURL = $this->getCurrentPageURL();
        }

        if (empty($this->publisherURL)) {
            $this->sourceURL = $this->getCurrentServerURL();
        }

        // Generate OPF data:
        $this->opf->setIdent("BookId");
        $this->opf->initialize($this->title, $this->language, $this->identifier, $this->identifierType);

        $DCdate = new DublinCore(DublinCore::DATE, gmdate($this->dateformat, $this->date));
        $DCdate->addOpfAttr("event", "publication");
        $this->opf->metadata->addDublinCore($DCdate);

        if (!empty($this->description)) {
            $this->opf->addDCMeta(DublinCore::DESCRIPTION, $this->decodeHtmlEntities($this->description));
        }

        if (!empty($this->publisherName)) {
            $this->opf->addDCMeta(DublinCore::PUBLISHER, $this->decodeHtmlEntities($this->publisherName));
        }

        if (!empty($this->publisherURL)) {
            $this->opf->addDCMeta(DublinCore::RELATION, $this->decodeHtmlEntities($this->publisherURL));
        }

        if (!empty($this->author)) {
            $author = $this->decodeHtmlEntities($this->author);
            $this->opf->addCreator($author, $this->decodeHtmlEntities($this->authorSortKey), MarcCode::AUTHOR);
            $this->ncx->setDocAuthor($author);
        }

        if (!empty($this->rights)) {
            $this->opf->addDCMeta(DublinCore::RIGHTS, $this->decodeHtmlEntities($this->rights));
        }

        if (!empty($this->coverage)) {
            $this->opf->addDCMeta(DublinCore::COVERAGE, $this->decodeHtmlEntities($this->coverage));
        }

        if (!empty($this->sourceURL)) {
            $this->opf->addDCMeta(DublinCore::SOURCE, $this->sourceURL);
        }

        if (!empty($this->relation)) {
            $this->opf->addDCMeta(DublinCore::RELATION, $this->decodeHtmlEntities($this->relation));
        }

        if ($this->isCoverImageSet) {
            $this->opf->addMeta("cover", "CoverImage");
        }

        if (!empty($this->generator)) {
            $gen = $this->decodeHtmlEntities($this->generator);
            $this->opf->addMeta("generator", $gen);
            $this->ncx->addMetaEntry("dtb:generator", $gen);
        }

        if ($this->EPubMark) {
            $this->opf->addMeta("generator", "EPub (Version " . self::VERSION . ") by A. Grandt, http://www.phpclasses.org/package/6115 or https://github.com/Grandt/PHPePub/");
        }

        reset($this->ncx->chapterList);
        list($firstChapterName, $firstChapterNavPoint) = each($this->ncx->chapterList);
        /** @var $firstChapterNavPoint NavPoint */
        $firstChapterFileName = $firstChapterNavPoint->getContentSrc();
        $this->opf->addReference(Reference::TEXT, $this->decodeHtmlEntities($firstChapterName), $firstChapterFileName);

        $this->ncx->setUid($this->identifier);

        $this->ncx->setDocTitle($this->decodeHtmlEntities($this->title));

        $this->ncx->referencesOrder = $this->referencesOrder;
        if ($this->isReferencesAddedToToc) {
            $this->ncx->finalizeReferences();
        }

        $this->finalizeTOC();

        if (!$this->isEPubVersion2()) {
            $this->addEPub3TOC("epub3toc.xhtml", $this->buildEPub3TOC());
        }

        $opfFinal = $this->fixEncoding($this->opf->finalize());
        $ncxFinal = $this->fixEncoding($this->ncx->finalize());

        if (mb_detect_encoding($opfFinal, 'UTF-8', true) === "UTF-8") {
            $this->zip->addFile($opfFinal, $this->bookRoot . "book.opf");
        } else {
            $this->zip->addFile(mb_convert_encoding($opfFinal, "UTF-8"), $this->bookRoot . "book.opf");
        }

        if (mb_detect_encoding($ncxFinal, 'UTF-8', true) === "UTF-8") {
            $this->zip->addFile($ncxFinal, $this->bookRoot . "book.ncx");
        } else {
            $this->zip->addFile(mb_convert_encoding($ncxFinal, "UTF-8"), $this->bookRoot . "book.ncx");
        }

        $this->opf = null;
        $this->ncx = null;

        $this->isFinalized = true;

        return true;
    }

    /**
     * Generates an UUID.
     *
     * Default version (4) will generate a random UUID, version 3 will URL based UUID.
     *
     * Added for convinience
     *
     * @param int    $bookVersion UUID version to retrieve, See lib.uuid.manual.html for details.
     * @param string $url
     *
     * @return string The formatted uuid
     */
    function createUUID($bookVersion = 4, $url = null) {
        return \UUID::mint($bookVersion, $url, \UUID::nsURL);
    }

    /**
     * Get the url of the current page.
     * Example use: Default Source URL
     *
     * $return string Page URL.
     */
    function getCurrentPageURL() {
        $pageURL = $this->getCurrentServerURL() . filter_input(INPUT_SERVER, "REQUEST_URI");

        return $pageURL;
    }

    /**
     * Get the url of the server.
     * Example use: Default Publisher URL
     *
     * $return string Server URL.
     */
    function getCurrentServerURL() {
        $serverURL = 'http';
        $https = filter_input(INPUT_SERVER, "HTTPS");
        $port = filter_input(INPUT_SERVER, "SERVER_PORT");

        if ($https === "on") {
            $serverURL .= "s";
        }
        $serverURL .= "://" . filter_input(INPUT_SERVER, "SERVER_NAME");
        if ($port != "80") {
            $serverURL .= ":" . $port;
        }

        return $serverURL . '/';
    }

    /**
     * Finalize and build final ePub structures.
     *
     * @return bool
     */
    private function finalizeTOC() {
        if (!$this->buildTOC) {
            return false;
        }

        if (empty($this->tocTitle)) {
            $this->tocTitle = "Table of Contents";
        }

        $tocData = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";

        if ($this->isEPubVersion2()) {
            $tocData .= "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\"\n"
                . "    \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">\n"
                . "<html xmlns=\"http://www.w3.org/1999/xhtml\">\n"
                . "<head>\n<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />\n";
        } else {
            $tocData .= "<html xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:epub=\"http://www.idpf.org/2007/ops\">\n"
                . "<head>\n<meta http-equiv=\"Default-Style\" content=\"text/html; charset=utf-8\" />\n";
        }

        if (!empty($this->tocCssFileName)) {
            $tocData .= "<link rel=\"stylesheet\" type=\"text/css\" href=\"" . $this->tocCssFileName . "\" />\n";
        }

        $tocData .= "<title>" . $this->tocTitle . "</title>\n"
            . "</head>\n"
            . "<body>\n"
            . "<h3>" . $this->tocTitle . "</h3>\n<div";

        if (!empty($this->tocCSSClass)) {
            $tocData .= " class=\"" . $this->tocCSSClass . "\"";
        }
        $tocData .= ">\n";

        while (list($item, $descriptive) = each($this->referencesOrder)) {
            if ($item === "text") {
                while (list($chapterName, $navPoint) = each($this->ncx->chapterList)) {
                    /** @var $navPoint NavPoint */
                    $fileName = $navPoint->getContentSrc();
                    $level = $navPoint->getLevel() - 2;
                    $tocData .= "\t<p>" . str_repeat(" &#160;  &#160;  &#160;", $level) . "<a href=\"" . $fileName . "\">" . $chapterName . "</a></p>\n";
                }
            } else {
                if ($this->tocAddReferences === true) {
                    if (array_key_exists($item, $this->ncx->referencesList)) {
                        $tocData .= "\t<p><a href=\"" . $this->ncx->referencesList[$item] . "\">" . $descriptive . "</a></p>\n";
                    } else {
                        if ($item === "toc") {
                            $tocData .= "\t<p><a href=\"TOC.xhtml\">" . $this->tocTitle . "</a></p>\n";
                        } else {
                            if ($item === "cover" && $this->isCoverImageSet) {
                                $tocData .= "\t<p><a href=\"CoverPage.xhtml\">" . $descriptive . "</a></p>\n";
                            }
                        }
                    }
                }
            }
        }
        $tocData .= "</div>\n</body>\n</html>\n";

        $this->addReferencePage($this->tocTitle, $this->tocFileName, $tocData, Reference::TABLE_OF_CONTENTS);

        return true;
    }

    /**
     * @param string $fileName
     * @param string $tocData
     *
     * @return bool
     */
    function addEPub3TOC($fileName, $tocData) {
        if ($this->isEPubVersion2() || $this->isFinalized || array_key_exists($fileName, $this->fileList)) {
            return false;
        }
        if (!$this->isInitialized) {
            $this->initialize();
        }

        $fileName = RelativePath::getRelativePath($fileName);
        $fileName = preg_replace('#^[/\.]+#i', "", $fileName);

        $this->zip->addFile($tocData, $this->bookRoot . $fileName);

        $this->fileList[$fileName] = $fileName;
        $this->opf->addItem("toc", $fileName, "application/xhtml+xml", "nav");

        return true;
    }

    /**
     * @param string $cssFileName
     * @param string $title
     *
     * @return string
     */
    function buildEPub3TOC($cssFileName = null, $title = "Table of Contents") {
        $this->ncx->referencesOrder = $this->referencesOrder;
        $this->ncx->setDocTitle($this->decodeHtmlEntities($this->title));

        return $this->ncx->finalizeEPub3($title, $cssFileName);
    }

    /**
     * Ensure the encoded string is a valid UTF-8 string.
     *
     * Note, that a mb_detect_encoding on the returned string will still return ASCII if the entire string is comprized of characters in the 1-127 range.
     *
     * @link: http://snippetdb.com/php/convert-string-to-utf-8-for-mysql
     *
     * @param string $in_str
     *
     * @return string converted string.
     */
    function fixEncoding($in_str) {
        if (mb_detect_encoding($in_str) == "UTF-8" && mb_check_encoding($in_str, "UTF-8")) {
            return $in_str;
        } else {
            return utf8_encode($in_str);
        }
    }

    /**
     * Return the finalized book.
     *
     * @return string with the book in binary form.
     */
    function getBook() {
        if (!$this->isFinalized) {
            $this->finalize();
        }

        return $this->zip->getZipData();
    }

    /**
     * Return the finalized book size.
     *
     * @return string
     */
    function getBookSize() {
        if (!$this->isFinalized) {
            $this->finalize();
        }

        return $this->zip->getArchiveSize();
    }

    /**
     * Send the book as a zip download
     *
     * Sending will fail if the output buffer is in use. You can override this limit by
     *  calling setIgnoreEmptyBuffer(TRUE), though the function will still fail if that
     *  buffer is not empty.
     *
     * @param string $fileName The name of the book without the .epub at the end.
     *
     * @return string|bool The sent file name if successful, FALSE if it failed.
     */
    function sendBook($fileName) {
        if (!$this->isFinalized) {
            $this->finalize();
        }

        if (!$this->endsWith($fileName, ".epub")) {
            $fileName .= ".epub";
        }

        if (true === $this->zip->sendZip($fileName, "application/epub+zip")) {
            return $fileName;
        }

        return false;
    }

    /**
     * Try to determine the mimetype of the file path.
     *
     * @param string $source Path
     *
     * @return string mimetype, or FALSE.
     * @deprecated Use getMimeTypeFromExtension(string $extension) instead.
     */
    function getMime($source) {
        return $this->getMimeTypeFromExtension(pathinfo($source, PATHINFO_EXTENSION));
    }

    /**
     * Retrieve an array of file names currently added to the book.
     * $key is the filename used in the book
     * $value is the original filename, will be the same as $key for most entries
     *
     * @return array file list
     */
    function getFileList() {
        return $this->fileList;
    }

    /**
     * Set default chapter target size.
     * Default is 250000 bytes, and minimum is 10240 bytes.
     *
     * @param int $size segment size in bytes
     *
     * @return void
     */
    function setSplitSize($size) {
        $this->splitDefaultSize = (int)$size;
        if ($size < 10240) {
            $this->splitDefaultSize = 10240; // Making the file smaller than 10k is not a good idea.
        }
    }

    /**
     * Get the chapter target size.
     *
     * @return int $size
     */
    function getSplitSize() {
        return $this->splitDefaultSize;
    }

    /**
     * Simply remove all HTML tags, brute force and no finesse.
     *
     * @param string $string html
     *
     * @return string
     */
    function html2text($string) {
        return preg_replace('~<[^>]*>~', '', $string);
    }

    /**
     * @return string
     */
    function getLog() {
        return $this->log->getLog();
    }
}
