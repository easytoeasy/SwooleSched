<?php


use PHPUnit\Framework\TestCase;

include __DIR__ . "/../../vendor/autoload.php";

final class TestIsint extends TestCase
{

    public function testIsint()
    {
        $ret = is_int("1");
        var_dump($ret);
        $ret = is_numeric("1");
        var_dump($ret);
    }

}