<?php

namespace ShortPixel;

class Source {
    private $key, $urls;

    public static function fromFile($path) {
        if(!file_exists($path)) throw new ClientException("File not found");
        return self::fromBuffer(file_get_contents($path));
    }

    public static function fromBuffer($string) {
        return new Result(array(), $string); //dummy
    }

    public static function fromUrls($urls) {
        if(!is_array($urls)) {
            $urls = array($urls);
        }
        if(count($urls) > 100) {
            throw new ClientException("Maximum 100 images allowed per call.");
        }

        $images = array_map ('utf8_encode',  $urls);
        $data       = array(
            "plugin_version" => "shortpixel-sdk 1.0.0" ,
            "key" =>  ShortPixel::getKey(),
            "urllist" => $images
        );

        return new Commander($data);
    }

    public function __construct($key, $urls) {
        $this->key = $key;
        $this->urls = $urls;
    }
}
