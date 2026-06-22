<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
;

$config = new PhpCsFixer\Config();
return $config->setRules(array(
        '@PSR2' => true,
        '@Symfony' => true,
        'phpdoc_annotation_without_dot' => false,
        'phpdoc_no_alias_tag' => false,
        'phpdoc_separation' => false,
        'yoda_style' => false,
    ))
    ->setFinder($finder)
    ->setUsingCache(false)
;
