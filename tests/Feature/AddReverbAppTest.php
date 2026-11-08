<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Tests\TestCase;

class AddReverbAppTest extends TestCase
{
    public function test_add_app_writes_env_that_config_turns_into_reverb_apps(): void
    {
        $dir = sys_get_temp_dir().'/reverb-hub-test-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/.env", "APP_NAME=x\nREVERB_APPS=\n");
        $this->app->useEnvironmentPath($dir);

        $this->artisan('reverb:add-app', ['name' => 'bayar-siap', '--origins' => 'bayarsiap.com, *.bayarsiap.com'])
            ->expectsOutputToContain('REVERB_APP_KEY=')
            ->assertSuccessful();
        $this->artisan('reverb:add-app', ['name' => 'second'])->assertSuccessful();
        $this->artisan('reverb:add-app', ['name' => 'second'])->assertFailed();
        $this->artisan('reverb:add-app', ['name' => 'bad', '--origins' => 'a"b'])->assertFailed();

        $vars = Dotenv::parse(file_get_contents("$dir/.env"));
        $this->assertSame('bayar-siap,second', $vars['REVERB_APPS']);

        foreach ($vars as $k => $v) {
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        try {
            $apps = (require config_path('reverb.php'))['apps']['apps'];
        } finally {
            foreach ($vars as $k => $v) {
                unset($_ENV[$k], $_SERVER[$k]);
            }
            unlink("$dir/.env");
            rmdir($dir);
        }

        $this->assertCount(2, $apps);
        $this->assertSame($vars['REVERB_APP_BAYAR_SIAP_KEY'], $apps[0]['key']);
        $this->assertSame($vars['REVERB_APP_BAYAR_SIAP_SECRET'], $apps[0]['secret']);
        $this->assertSame(['bayarsiap.com', '*.bayarsiap.com', ''], $apps[0]['allowed_origins']);
        $this->assertSame([''], $apps[1]['allowed_origins']);
        $this->assertNotSame($apps[0]['key'], $apps[1]['key']);
    }
}
