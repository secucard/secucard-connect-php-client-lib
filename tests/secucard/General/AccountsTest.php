<?php

namespace secucard\tests\General;

use secucard\models\General\Accounts;
use secucard\tests\Api\ClientTest;

/**
 * @covers secucard\models\General\Accounts
 */
class AccountsTest extends ClientTest
{
    /**
     * @test
     */
    public function testGetList()
    {
        $list = $this->client->general->accounts->getList(array());

        $this->assertFalse(empty($list));
    }

    /**
     * @test
     */
    public function testGetItem()
    {
        $list = $this->client->general->accounts->getList(array('count'=>1));

        $this->assertFalse(empty($list));
        $sample_item_id = $list[0]->id;
        $this->assertFalse(empty($sample_item_id), 'Cannot get one item, because none is available');

        if ($sample_item_id) {
            $item = $this->client->general->accounts->get($sample_item_id);

            $this->assertFalse(empty($item));
        }
    }
}