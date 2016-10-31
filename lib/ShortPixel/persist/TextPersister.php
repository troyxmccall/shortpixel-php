<?php
/**
 * User: simon
 * Date: 19.08.2016
 * Time: 18:05
 */
namespace ShortPixel\persist;

use \ShortPixel\Persister;
use \ShortPixel\ShortPixel;

/**
 * Class TextPersister - save the optimization information in .shortpixel files in the current folder of the images
 * @package ShortPixel\persist
 */
class TextPersister implements Persister {

    private $fp;
    private $options;
    private STATIC $ALLOWED_STATUSES = array('pending', 'success', 'skip', 'deleted');
    private STATIC $ALLOWED_TYPES = array('I', 'D');

    function __construct($options)
    {
        $this->options = $options;
        $this->fp = array();
    }

    function isOptimized($path)
    {
        if(!file_exists($path)) {
            return false;
        }
        $fp = $this->openMetaFile(dirname($path), 'read');
        if(!$fp) {
            return false;
        }
        array_push($this->fp, $fp);

        while (($line = fgets($fp)) !== FALSE) {
            $data = $this->parse($line);
            if($data->file === basename($path) && $data->status == 'success' ) {
                return true;
            }
        }
        fclose($fp);
        array_pop($this->fp);

        return false;
    }

    function getTodo($path, $count, $nextFollows = false)
    {
        if(!file_exists($path) || !is_dir($path)) {
            return array();
        }
        $fp = $this->openMetaFile($path);
        if(!$fp) {
            return false;
        }

        array_push($this->fp, $fp);

        $files = scandir($path);

        $dataArr = array(); $err = false;
        for ($i = 0; ($line = fgets($fp)) !== FALSE; $i++) {
            $data = $this->parse($line);
            if($data) {
                $data->filePos = $i;
                $dataArr[$data->file] = $data;
            } else {
                $err = true;
            }
        }
        if($err) { //at least one error found in the .shortpixel file, rewrite it
            fseek($fp, 0);
            ftruncate($fp, 0);
            foreach($dataArr as $meta) {
                fwrite($fp, $this->assemble($meta));
                fwrite($fp, $line . "\r\n");
            }
        }

        $results = array();
        $ignore = array('.','..');
        $remain = $count;
        foreach($files as $file) {
            $filePath = $path . '/' . $file;
            if(in_array($file, $ignore)
               || (!ShortPixel::isProcessable($file) && !is_dir($filePath))
               || isset($dataArr[$file]) && $dataArr[$file]->status == 'deleted'
               || isset($dataArr[$file])
                  && ($dataArr[$file]->status == 'success' || $dataArr[$file]->status == 'skip')
                  && filesize($filePath) == $dataArr[$file]->optimizedSize) {
                continue;
            }
            if(is_dir($filePath)) {
                if(!isset($dataArr[$file])) {
                    $this->appendMeta($this->newMeta($file));
                }
                $resultsSubfolder =  $this->getTodo($filePath, $count, $nextFollows);
                if(count($resultsSubfolder)) {
                    return $resultsSubfolder;
                } //otherwise ignore the folder;
            } else {
                if(isset($dataArr[$file])) {
                    if(($dataArr[$file]->status == 'success') && (filesize($filePath) !== $dataArr[$file]->optimizedSize)) {
                        $dataArr[$file]->status = 'pending';
                        $dataArr[$file]->optimizedSize = 0;
                        $dataArr[$file]->changeDate = time();
                        $this->updateMeta($dataArr[$file]);
                    }
                    elseif($dataArr[$file]->status == 'error') {
                        $dataArr[$file]->retries += 1;
                        if($dataArr[$file]->retries >= ShortPixel::MAX_RETRIES) {
                            $dataArr[$file]->status = 'skip';
                        }
                        $this->updateMeta($dataArr[$file]);
                        if($dataArr[$file]->retries >= ShortPixel::MAX_RETRIES) {
                            continue;
                        }
                    }
                }
                elseif(!isset($dataArr[$file])) {
                    $this->appendMeta($this->newMeta($file));
                }

                $results[] = $filePath;
                $remain--;

                if($remain <=0) {
                    return $results;
                }
            }
        }

        fclose($fp);
        array_pop($this->fp);

        if(count($results) == 0) {//folder is empty or completely optimized, if it's a subfolder of another optimized folder, mark it as such in the parent .shortpixel file
            if(file_exists(dirname($path) . '/' . ShortPixel::opt("persist_name"))) {
                $this->setOptimized($path);
            }
        }
        return  $results;
    }

    function getNextTodo($path, $count)
    {
        // TODO: Implement getNextTodo() method.
    }

    function doneGet()
    {
        // TODO: Implement doneGet() method.
    }

    function getOptimizationData($path)
    {
        // TODO: Implement getOptimizationData() method.
    }

    function setPending($path)
    {
        // TODO: Implement setPending() method.
    }

    function setOptimized($path, $optData = array())
    {
        $toClose = false;
        $fp = end($this->fp);
        if(!$fp) {
            $fp = $this->openMetaFile(dirname($path));
            array_push($this->fp, $fp);
            if(!$fp) {
                return false;
            }
            $toClose = true;
        }
        $meta = $this->findMeta($path);
        if($meta) {
            $meta->status = 'success';
            $meta->retries++;
            $metaArr = array_merge((array)$meta, $optData);
            $this->updateMeta((object)$metaArr, false);
        } else {
            $meta = $this->newMeta($path);
            $meta->status = 'success';
            $metaArr = array_merge((array)$meta, $optData);
            $this->appendMeta((object)$metaArr, false);
        }
        if($toClose) {
            fclose($fp);
            array_pop($this->fp);
        }
        return true;
    }

    function setFailed($path, $optData)
    {
        // TODO: Implement setFailed() method.
    }

    protected function openMetaFile($path, $type = 'update') {
        $metaFile = $path . '/' . ShortPixel::opt("persist_name");
        $fp = fopen($metaFile, $type == 'update' ? 'c+' : 'r');
        return $fp;
    }

    protected function findMeta($path) {
        $fp = end($this->fp);
        fseek($fp, 0);
        for ($i = 0; ($line = fgets($fp)) !== FALSE; $i++) {
            $data = $this->parse($line);
            if($data->file === basename($path)) {
                $data->filePos = $i;
                return $data;
            }
        }
        return false;
    }

    /**
     * @param $meta
     * @param bool|false $returnPointer - set this to true if need to have the file pointer back afterwards, such as when updating while reading the file line by line
     */
    protected function updateMeta($meta, $returnPointer = false) {
        $fp = end($this->fp);
        if($returnPointer) {
            $crt = ftell($fp);
        }
        fseek($fp, self::LINE_LENGTH * $meta->filePos); // +2 for the \r\n
        fwrite($fp, $this->assemble($meta));
        fflush($fp);
        if($returnPointer) {
            fseek($fp, $crt);
        }
    }

    /**
     * @param $meta
     * @param bool|false $returnPointer - set this to true if need to have the file pointer back afterwards, such as when updating while reading the file line by line
     */
    protected function appendMeta($meta, $returnPointer = false) {
        $fp = end($this->fp);
        if($returnPointer) {
            $crt = ftell($fp);
        }
        fseek($fp, 0, SEEK_END);
        $line = $this->assemble($meta);
        //$ob = $this->parse($line);
        fwrite($fp, $line . "\r\n");
        fflush($fp);
        if($returnPointer) {
            fseek($fp, $crt);
        }
    }

    protected function newMeta($file) {
        return (object) array(
            "type" => is_dir($file) ? 'D' : 'I',
            "status" => 'pending',
            "retries" => 0,
            "compressionType" => $this->options['lossy'] == 1 ? 'lossy' : 'lossless',
            "keepExif" => $this->options['keep_exif'],
            "cmyk2rgb" => $this->options['cmyk2rgb'],
            "resize" => $this->options['resize_width'] ? 1 : 0,
            "resizeWidth" => $this->options['resize_width'],
            "resizeHeight" => $this->options['resize_height'],
            "convertto" => $this->options['convertto'],
            "percent" => null,
            "optimizedSize" => null,
            "changeDate" => time(),
            "file" => $file,
            "message" => '');
    }

    const LINE_LENGTH = 445; //including the \r\n at the end

    protected function parse($line) {
        if(strlen(rtrim($line, "\r\n")) != (self::LINE_LENGTH - 2)) return false;
        $ret = (object) array(
            "type" => trim(substr($line, 0, 2)),
            "status" => trim(substr($line, 2, 11)),
            "retries" => trim(substr($line, 13, 2)),
            "compressionType" => trim(substr($line, 15, 9)),
            "keepExif" => trim(substr($line, 24, 2)),
            "cmyk2rgb" => trim(substr($line, 26, 2)),
            "resize" => trim(substr($line, 28, 2)),
            "resizeWidth" => 0 + trim(substr($line, 30, 6)),
            "resizeHeight" => 0 + trim(substr($line, 36, 6)),
            "convertto" => trim(substr($line, 42, 2)),
            "percent" => 0.0 + trim(substr($line, 52, 6)),
            "optimizedSize" => 0 + trim(substr($line, 58, 9)),
            "changeDate" => strtotime(trim(substr($line, 67, 20))),
            "file" => trim(substr($line, 87, 256)),
            "message" => trim(substr($line, 343, 100)),
        );
        if(!in_array($ret->status, self::$ALLOWED_STATUSES) || !$ret->changeDate) {
            return false;
        }
        return $ret;
    }

    protected function assemble($data) {
        return sprintf("%s%s%s%s%s%s%s%s%s%s%s%s%s%s%s",
            str_pad($data->type, 2),
            str_pad($data->status, 11),
            str_pad($data->retries, 2),
            str_pad($data->compressionType, 9),
            str_pad($data->keepExif, 2),
            str_pad($data->cmyk2rgb, 2),
            str_pad($data->resize, 2),
            str_pad(substr(number_format($data->resizeWidth, 0, ".", ""),0 , 5), 6),
            str_pad(substr(number_format($data->resizeHeight, 0, ".", ""),0 , 5), 6),
            str_pad($data->convertto, 10),
            str_pad(substr(number_format($data->percent, 2, ".",""),0 , 5), 6),
            str_pad(substr(number_format($data->optimizedSize, 0, ".", ""),0 , 8), 9),
            str_pad(date("Y-m-d H:i:s", $data->changeDate), 20),
            str_pad(substr($data->file, 0, 255), 256),
            str_pad(substr($data->message, 0, 99), 100)
        );
    }
}