<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>
<head>
    <meta http-equiv="Content-type" content="text/html; charset=utf-8"/>
    <title>Modified Core Files Report by Amasty</title>
    <link rel="stylesheet" href="styles.css" type="text/css" charset="utf-8"/>
    <script>
        function hideNextDiv(el) {
            var yourUl = el.parentNode.childNodes[4];
            yourUl.style.display = yourUl.style.display === 'none' ? '' : 'none';
        }
    </script>
</head>
<body>

<h1 style="text-align: center" >Modified Core Files Report by Amasty</h1>

<?php

$mageFilename = getcwd() . '/../app/Mage.php';
if (!file_exists($mageFilename)) {
    echo 'Mage file not found';
    exit;
}
require $mageFilename;
Mage::app();

require_once dirname(__FILE__) . '/lib/Diff.php';
require_once dirname(__FILE__) . '/lib/Diff/Renderer/Html/SideBySide.php';

$ama = new Amasty_Differ("http://cds.amasty.net/");

if (isset($_GET['limit']))
    $ama->setErrorLimith($_GET['limit']);



$ama->checkFiles();


class Amasty_Differ
{
    private $_host = '';

    private $_defaultDirs = array('./api.php',
                                  './cron.php',
                                  './get.php',
                                  './index.php',
                                  './install.php',
                                  './app/Mage.php',
                                  './app/bootstrap.php',
                                  './app/code/community/Cm/RedisSession/',
                                  './app/code/community/Phoenix/Moneybookers/',
                                  './app/code/core/',
                                  './lib/',
                                  './js/');

    private $_differOptions
        = array(
            'ignoreWhitespace' => true,
            'ignoreNewLines'   => true,
            'ignoreCase'       => false,
        );

    private $_enterpriseFileError = 'Enterprise file wrong, please check it manually';

    private $_magentoVersion = '';

    private $_errorLimith = 5;

    private $_allowedFileTypes = array( 'php','htm','html','phtml','js','xml','css' );

    private $timeStart = 0;

    /**
     * @param string $host Remote host with clear files and MD5 sums
     */
    function __construct($host)
    {
        $this->_host = $host;
        $this->_magentoVersion = Mage::getVersion();
        // At start of script
        $this->timeStart = microtime(true);
    }

    /**
     * @param array $defaultDirs
     */
    public function setDefaultDirs($defaultDirs)
    {
        $this->_defaultDirs = $defaultDirs;
    }

    /**
     * @param int $errorLimith
     */
    public function setErrorLimith($errorLimith)
    {
        $this->_errorLimith = $errorLimith;
    }

    /**
     * @param array $differOptions
     */
    public function setDifferOptions($differOptions)
    {
        $this->_differOptions = $differOptions;
    }

    /**
     * @return array
     */
    private function _loadDefaultDataArray()
    {
        $edition = $this->_getEdition();
        $file = $this->_loadFromUrl($this->_host . $edition.'/'. $this->_magentoVersion . ".md5", true);
        $strings = explode(PHP_EOL, $file);
        $dirsToScan = $this->_defaultDirs;

        foreach ($strings as $key => $string) {
            foreach ($dirsToScan as $dir) {

                preg_match('@\..+?\.(.*)@',$string,$fileInfo);

                if (strpos($string, $dir) && (isset($fileInfo[1]) ) && in_array( $fileInfo[1],$this->_allowedFileTypes ) ) {
                    //delete ./
                    $string = str_replace('./','',$string);
                    $strings[$key] = $string;
                    continue 2;
                }
            }
            unset($strings[$key]);
        }
        return $strings;
    }

    /**
     * @param string $url
     * @param bool   $checkFile
     *
     * @return bool|mixed
     */
    private function _loadFromUrl($url, $checkFile = false)
    {
        $ch = curl_init();
        $timeOut = 10;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeOut);
        curl_setopt($ch, CURLOPT_ENCODING , "gzip");

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE); // Follow redirects

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode != 200 && $checkFile) {
            die("Couldn't get file from Amasty server");
        } elseif ($httpCode != 200) {
            return false;
        }
        curl_close($ch);
        return $data;
    }

    /**
     * @return bool
     */
    private function _getEdition()
    {
        if (method_exists('Mage', 'getEdition')) {
            return  strtolower( Mage::getEdition() );
        } else {
            if (version_compare(Mage::getVersion(), '1.8.0.0', '<')) {
                return 'community';
            }
        }
        return 'enterprise';
    }


    /**
     * @param array $dir list of checked directories
     */
    public function checkFiles()
    {
        $counter = 0;
        try {
            $changed = array();
            $data = $this->_loadDefaultDataArray();

            foreach ($data as $string) {
                if ($counter >= $this->_errorLimith ) break;
                list($clearMd5, $filePath) = explode("  ", $string);

                if (empty($filePath)) {
                    continue;
                }
                $file = file_get_contents( Mage::getBaseDir() . "/" . $filePath );
                $file = str_replace("\r\n","\n",$file);
                $file = str_replace("\r","\n",$file);
                $serverMd5 = md5($file);
                if ($clearMd5 != $serverMd5) {
                    $changed[] = $this->_compareFiles($filePath);
                    $counter++;
                }
            }

            $redefined = array();
            $counter = 0;
            foreach ($this->_getLocalMageFiles() as $path) {
                if ($counter >= $this->_errorLimith) {
                    break;
                }
                $redefined[] = $this->_compareFiles($path, true);
                $counter++;
            }
        }catch (Exception $e){
            echo $e->getMessage();
            echo '<h1>Couldn\'t check files</h1>';
            exit;
        }

        $output = 0;
        if (!empty($changed) ) {
            echo '<h2>Modified files</h2>';
            echo implode( '<br>',$changed);
            $output = 1;
        }

        if( !empty($redefined) ) {
            echo '<h2>Redefined files</h2>';
            echo implode( '<br>', $redefined );
            $output = 1;
        }

        if ($output==0) {
            echo '<h2 style="text-align: center" >Congratulations! Your Magento is clean!</h2>';
        }

    }

    /**
     * @param $filePath
     *
     * @return string
     */
    private function _compareFiles($filePath, $isLocal = false)
    {
        $serverFile = explode(PHP_EOL, file_get_contents(Mage::getBaseDir() . '/' . $filePath));
        $serverFile = str_replace("\n","",$serverFile);
        $serverFile = str_replace("\r","",$serverFile);

        $filePath = $isLocal == false ? $filePath : str_replace('/local/','/core/',$filePath);

        $edition = $this->_getEdition();

        $clearFile = explode(
            PHP_EOL,
            $this->_loadFromUrl(
                $this->_host .$edition.'/'. $this->_magentoVersion . '/' .$filePath
            )
        );

        $clearFile = str_replace("\n","",$clearFile);
        $clearFile = str_replace("\r","",$clearFile);

        if  ( $edition =='enterprise' && count($clearFile)==1) {
            return '<div class="message" > ' . $this->_enterpriseFileError.' file path - '.$filePath . ' </div>';
        }
        return $this->_getDiffHtml($clearFile, $serverFile, $filePath);
    }


    /**
     * @return array|RegexIterator
     */
    private function _getLocalMageFiles()
    {
        if (!file_exists( Mage::getBaseDir('code') . '/local/Mage/' )) return false;
        $dirIterator = new RecursiveDirectoryIterator(
            Mage::getBaseDir('code') . '/local/Mage/',
            RecursiveDirectoryIterator::SKIP_DOTS
        );
        $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);

        $files = new RegexIterator($iterator, '/(^.+\.php)$/i', RecursiveRegexIterator::GET_MATCH);

        $files = iterator_to_array($files);

        $files = array_keys($files);

        array_walk(
            $files, function (&$value, $key) {$value = str_replace(Mage::getBaseDir() . '/', '', $value);}
        );

        return $files;
    }

    /**
     * @param $clearFile
     * @param $serverFile
     * @param $filePath
     *
     * @return string
     */
    private function _getDiffHtml($clearFile, $serverFile, $filePath)
    {

        // Initialize the diff class
        $diff = new Diff($clearFile, $serverFile, $this->_differOptions);

        $renderer = new Diff_Renderer_Html_SideBySide();

        $diffHtml = $diff->Render($renderer);

        if ( $diffHtml == '' ) {
            $template = "<div class='message' >" . $filePath
                . " ";

            $template .= '  equal redefinied files </div>';
            return $template;
        }
        $template = "<div class='message' >" . $filePath
            . " <a href='javascript:void(0)' onclick='hideNextDiv(this)' >view differences</a> <br>";
        $template .= '<div style="display: none" >';

        $template .= $diffHtml.' </div> </div>';
        return $template;
    }

}

?>

</body>
</html>
