<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use RiseTechApps\RiseTools\Features\EmailValidator\EmailValidator;
use RiseTechApps\RiseTools\Features\EmailValidator\Rules\NotDisposableEmail;
use RiseTechApps\RiseTools\Features\EmailValidator\Rules\NotRoleBasedEmail;
use RiseTechApps\RiseTools\Tests\TestCase;

class EmailValidatorTest extends TestCase
{
    public function test_format(): void
    {
        $validator = new EmailValidator();

        $this->assertTrue($validator->isValidFormat('ana@example.com'));
        $this->assertFalse($validator->isValidFormat('ana@'));
        $this->assertFalse($validator->isValidFormat('ana example.com'));
    }

    public static function roleBasedEmails(): array
    {
        return [
            ['rh@x.com', true],
            ['rh.sp@x.com', true],
            ['suporte2@x.com', true],
            ['no-reply@x.com', true],
            ['contato+loja@x.com', true],
            ['Info@x.com', true],
            ['rhaissa@x.com', false],
            ['informatica@x.com', false],
            ['adminson@x.com', false],
            ['joao@x.com', false],
        ];
    }

    #[DataProvider('roleBasedEmails')]
    public function test_role_based(string $email, bool $expected): void
    {
        $this->assertSame($expected, (new EmailValidator())->isRoleBased($email));
    }

    public function test_disposable_domain_and_subdomain(): void
    {
        $validator = new EmailValidator();

        $this->assertTrue($validator->isDisposable('x@mailinator.com'));
        $this->assertTrue($validator->isDisposable('x@eu.mailinator.com'));
        $this->assertFalse($validator->isDisposable('x@gmail.com'));
    }

    public function test_disposable_from_config_and_downloaded_list(): void
    {
        $path = $this->tempPath . '/disposable.txt';
        config(['risetools.email.disposable_list_path' => $path, 'risetools.email.disposable_domains' => ['Throwaway.Example']]);
        $validator = new EmailValidator();

        $this->assertTrue($validator->isDisposable('x@throwaway.example'));
        $this->assertFalse($validator->isDisposable('x@burner.test'));

        file_put_contents($path, "# comment\nburner.test\n\n");
        // Mesmo objeto (singleton no Octane): arquivo novo é recarregado.
        $this->assertTrue($validator->isDisposable('x@burner.test'));
        $this->assertTrue($validator->isDisposable('x@deep.sub.burner.test'));
        $this->assertFalse($validator->isDisposable('x@notburner.test'));
    }

    public function test_dns_lookups_are_cached_per_domain(): void
    {
        config([
            'cache.stores.email_file' => ['driver' => 'file', 'path' => $this->tempPath . '/cache'],
            'risetools.email.cache_store' => 'email_file',
        ]);
        // .test nunca resolve: só passa se vier do cache.
        app('cache')->resolve('email_file')->put('risetools:email:dns:example.test', ['mx' => ['mx.example.test' => 10], 'a' => false], 600);

        $validator = new EmailValidator();

        $this->assertTrue($validator->hasValidMxRecords('ana@Example.test'));
        $this->assertSame(['mx.example.test' => 10], $validator->getInfo('ana@example.test')['mx_records']);
    }

    public function test_dns_cache_can_be_disabled(): void
    {
        config(['risetools.email.dns_cache_minutes' => 0]);

        $this->assertFalse((new EmailValidator())->hasValidMxRecords('ana@domain-that-does-not-exist.invalid'));
    }

    public function test_validation_rules(): void
    {
        $rules = ['email' => ['email', new NotDisposableEmail(), new NotRoleBasedEmail()]];

        $this->assertTrue(Validator::make(['email' => 'ana.souza@gmail.com'], $rules)->passes());

        $disposable = Validator::make(['email' => 'x@mailinator.com'], $rules);
        $this->assertSame(['The email must not be a disposable e-mail address.'], $disposable->errors()->get('email'));

        $role = Validator::make(['email' => 'contato@empresa.com'], $rules);
        $this->assertSame(['The email must be a personal e-mail address.'], $role->errors()->get('email'));

        $custom = Validator::make(['email' => 'x@mailinator.com'], ['email' => [new NotDisposableEmail('Use seu e-mail real.')]]);
        $this->assertSame(['Use seu e-mail real.'], $custom->errors()->get('email'));
    }

    public function test_disposable_update_command(): void
    {
        $path = $this->tempPath . '/lists/disposable.txt';
        config(['risetools.email.disposable_list_path' => $path]);
        $body = "# header\n" . implode("\n", array_map(fn($i) => "temp{$i}.example", range(1, 150))) . "\nnot a domain\n";
        Http::fake(['lists.test/*' => Http::response($body)]);

        $this->assertSame(0, Artisan::call('risetools:email-disposable-update', ['--url' => 'https://lists.test/list.conf']));
        $this->assertStringContainsString('150 disposable domains saved', Artisan::output());
        $this->assertTrue((new EmailValidator())->isDisposable('x@temp42.example'));
    }

    public function test_disposable_update_keeps_the_current_list_on_bad_download(): void
    {
        $path = $this->tempPath . '/disposable.txt';
        file_put_contents($path, "good.example\n");
        config(['risetools.email.disposable_list_path' => $path]);
        Http::fake(['lists.test/*' => Http::sequence()->push('<html>error</html>')->push('', 500)]);

        $this->assertSame(1, Artisan::call('risetools:email-disposable-update', ['--url' => 'https://lists.test/a']));
        $this->assertStringContainsString('keeping the current one', Artisan::output());
        $this->assertSame(1, Artisan::call('risetools:email-disposable-update', ['--url' => 'https://lists.test/b']));
        $this->assertSame("good.example\n", file_get_contents($path));
    }

    public function test_smtp_multiline_response_is_read_entirely(): void
    {
        $stream = $this->stream("250-mx.example.com\r\n250-PIPELINING\r\n250 SIZE 1000\r\n550 5.1.1 unknown\r\n");

        [$code, $text] = $this->smtpRead($stream);
        $this->assertSame(250, $code);
        $this->assertSame(2, substr_count($text, "\n"));

        // A resposta seguinte não fica desalinhada com o comando seguinte.
        [$code] = $this->smtpRead($stream);
        $this->assertSame(550, $code);
    }

    public function test_smtp_single_line_response(): void
    {
        [$code, $text] = $this->smtpRead($this->stream("220 mx.example.com ESMTP\r\n"));

        $this->assertSame(220, $code);
        $this->assertSame('220 mx.example.com ESMTP', $text);
    }

    /**
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function smtpRead($stream): array
    {
        $validator = new EmailValidator();

        return (new \ReflectionMethod($validator, 'smtpRead'))->invoke($validator, $stream);
    }
}
