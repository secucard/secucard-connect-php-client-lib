<?php

namespace SecucardConnect\Auth;


use SecucardConnect\BaseClientTest;
use SecucardConnect\Util\Logger;

class DeviceAuthTest extends BaseClientTest
{
    protected function getCredentials()
    {
        return new DeviceCredentials(
            'your-id',
            'your-secret',
            'your-device');
    }

    /**
     * @test
     */
    public function testAuth()
    {
        $codes = $this->client->authenticate();

        $this->assertTrue($codes instanceof AuthCodes);

        $t = time() + 60 * 2;
        do {
            $result = $this->client->authenticate(['devicecode' => $codes->device_code]);
            if ($result === true) {
                break;
            }
            Logger::logInfo($this->logger, 'Auth pending, try again');
            sleep($codes->interval);
        } while (time() < $t);

        $this->assertTrue($result === true);
    }


}