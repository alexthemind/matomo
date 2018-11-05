<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\TwoFactorAuth\tests\Integration;

use Piwik\Container\StaticContainer;
use Piwik\Plugins\TwoFactorAuth\Dao\RecoveryCodeDao;
use Piwik\Plugins\TwoFactorAuth\SystemSettings;
use Piwik\Plugins\TwoFactorAuth\TwoFactorAuthentication;
use Piwik\Plugins\UsersManager\API;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group TwoFactorAuth
 * @group TwoFactorAuthenticationTest
 * @group Plugins
 */
class TwoFactorAuthenticationTest extends IntegrationTestCase
{
    /**
     * @var RecoveryCodeDao
     */
    private $dao;

    /**
     * @var SystemSettings
     */
    private $settings;

    /**
     * @var TwoFactorAuthentication
     */
    private $twoFa;

    public function setUp()
    {
        parent::setUp();

        foreach (['mylogin', 'mylogin1', 'mylogin2'] as $user) {
            API::getInstance()->addUser($user, '123abcDk3_l3', $user . '@matomo.org');
        }

        $this->dao = StaticContainer::get(RecoveryCodeDao::class);
        $this->settings = new SystemSettings();
        $this->twoFa = new TwoFactorAuthentication($this->settings, $this->dao);
    }

    public function test_isUserRequiredToHaveTwoFactorEnabled_notByDefault()
    {
        $this->assertFalse($this->twoFa->isUserRequiredToHaveTwoFactorEnabled());
    }

    public function test_isUserRequiredToHaveTwoFactorEnabled()
    {
        $this->settings->twoFactorAuthRequired->setValue(1);
        $this->assertTrue($this->twoFa->isUserRequiredToHaveTwoFactorEnabled());
    }

    public function test_saveSecret_disable2FAforUser_isUserUsingTwoFactorAuthentication()
    {
        $this->dao->createRecoveryCodesForLogin('mylogin');

        $this->assertFalse($this->twoFa->isUserUsingTwoFactorAuthentication('mylogin'));
        $this->twoFa->saveSecret('mylogin', '123456');

        $this->assertTrue($this->twoFa->isUserUsingTwoFactorAuthentication('mylogin'));
        $this->assertFalse($this->twoFa->isUserUsingTwoFactorAuthentication('mylogin2'));

        $this->twoFa->disable2FAforUser('mylogin');

        $this->assertFalse($this->twoFa->isUserUsingTwoFactorAuthentication('mylogin'));
    }

    public function test_disable2FAforUser_removesAllRecoveryCodes()
    {
        $this->dao->createRecoveryCodesForLogin('mylogin');
        $this->assertNotEmpty($this->dao->getAllRecoveryCodesForLogin('mylogin'));
        $this->twoFa->disable2FAforUser('mylogin');
        $this->assertEquals([], $this->dao->getAllRecoveryCodesForLogin('mylogin'));
    }

    /**
     * @expectedExceptionMessage Anonymous cannot use
     * @expectedException \Exception
     */
    public function test_saveSecret_neverWorksForAnonymous()
    {
        $this->twoFa->saveSecret('anonymous', '123456');
    }

    /**
     * @expectedExceptionMessage no recovery codes have been created
     * @expectedException \Exception
     */
    public function test_saveSecret_notWorksWhenNoRecoveryCodesCreated()
    {
        $this->twoFa->saveSecret('not', '123456');
    }

    public function test_isUserUsingTwoFactorAuthentication_neverWorksForAnonymous()
    {
        $this->assertFalse($this->twoFa->isUserUsingTwoFactorAuthentication('anonymous'));
    }

    public function test_validateAuthCodeDuringSetup()
    {
        $secret = '789123';
        $this->assertFalse($this->twoFa->validateAuthCodeDuringSetup('123456', $secret));

        $authCode = $this->generateValidAuthCode($secret);

        $this->assertTrue($this->twoFa->validateAuthCodeDuringSetup($authCode, $secret));
    }

    public function test_validateAuthCode_userIsNotUsingTwoFa()
    {
        $this->assertFalse($this->twoFa->validateAuthCode('mylogin', '123456'));
        $this->assertFalse($this->twoFa->validateAuthCode('mylogin', false));
        $this->assertFalse($this->twoFa->validateAuthCode('mylogin', null));
        $this->assertFalse($this->twoFa->validateAuthCode('mylogin', ''));
        $this->assertFalse($this->twoFa->validateAuthCode('mylogin', 0));
    }

    public function test_validateAuthCode_userIsUsingTwoFa_authenticatesThroughApp()
    {
        $secret1 = '123456';
        $secret2 = '654321';
        $this->dao->createRecoveryCodesForLogin('mylogin1');
        $this->dao->createRecoveryCodesForLogin('mylogin2');
        $this->twoFa->saveSecret('mylogin1', $secret1);
        $this->twoFa->saveSecret('mylogin2', $secret2);

        $authCode1 = $this->generateValidAuthCode($secret1);
        $authCode2 = $this->generateValidAuthCode($secret2);

        $this->assertTrue($this->twoFa->validateAuthCode('mylogin1', $authCode1));
        $this->assertTrue($this->twoFa->validateAuthCode('mylogin2', $authCode2));

        $this->assertFalse($this->twoFa->validateAuthCode('mylogin2', $authCode1));
        $this->assertFalse($this->twoFa->validateAuthCode('mylogin1', $authCode2));
        $this->assertFalse($this->twoFa->validateAuthCode('mylogin1', false));
        $this->assertFalse($this->twoFa->validateAuthCode('mylogin2', null));
        $this->assertFalse($this->twoFa->validateAuthCode('mylogin2', ''));
        $this->assertFalse($this->twoFa->validateAuthCode('mylogin1', 0));
    }

    public function test_validateAuthCode_userIsUsingTwoFa_authenticatesThroughRecoveryCode()
    {
        $this->dao->createRecoveryCodesForLogin('mylogin1');
        $this->dao->createRecoveryCodesForLogin('mylogin2');
        $this->twoFa->saveSecret('mylogin1', '123456');
        $this->twoFa->saveSecret('mylogin2', '654321');

        $codesLogin1 = $this->dao->getAllRecoveryCodesForLogin('mylogin1');
        $codesLogin2 = $this->dao->getAllRecoveryCodesForLogin('mylogin2');
        $this->assertNotEmpty($codesLogin1);
        $this->assertNotEmpty($codesLogin2);

        foreach ($codesLogin1 as $code) {
            // doesn't work cause belong to different user
            $this->assertFalse($this->twoFa->validateAuthCode('mylogin2', $code));
        }

        foreach ($codesLogin1 as $code) {
            $this->assertTrue($this->twoFa->validateAuthCode('mylogin1', $code));
        }

        foreach ($codesLogin1 as $code) {
            // no code can be used twice
            $this->assertFalse($this->twoFa->validateAuthCode('mylogin1', $code));
        }
    }

    private function generateValidAuthCode($secret)
    {
        $code = new \TwoFactorAuthenticator();
        return $code->getCode($secret);
    }
}
