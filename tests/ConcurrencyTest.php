<?php
declare(strict_types=1);
namespace Corpsakademie\Tests;

use Corpsakademie\Tests\Support\Fixture;
use OTPHP\TOTP;

final class ConcurrencyTest extends Fixture
{
    private function compete(array $job): array
    {
        $code= <<<'CHILD'
require $argv[1].'/vendor/autoload.php';
$job=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$clock=new \Corpsakademie\Tests\Support\TestClock(); $clock->timestamp=$job['time'];
$c=new \Corpsakademie\Container(new \Corpsakademie\Infrastructure\Config($argv[1],$job['config']),$clock,new \Corpsakademie\Tests\Support\TestMailer());
try {
    $won=$c->db->immediate(function() use ($c,$job): bool {
        if ($job['mode']==='token') { $c->tokens->consume('bootstrap',$job['token']); $won=true; }
        else { $won=$c->mfa->verify($job['user_id'],$job['code']); }
        usleep(100000);
        return $won;
    });
    echo $won?'won':'lost';
} catch (\DomainException) { echo 'lost'; }
CHILD;
        $config=$this->values([
            'APP_KEY'=>base64_encode($this->c->config->key),
            'APP_DB_PATH'=>$this->c->config->databasePath,'APP_STORAGE_PATH'=>$this->c->config->storagePath,
        ]);
        $job['config']=$config; $job['time']=$this->clock->timestamp;
        $processes=[];
        for ($i=0;$i<2;$i++) {
            $process=proc_open([PHP_BINARY,'-r',$code,$this->root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            self::assertIsResource($process);
            fwrite($pipes[0],json_encode($job,JSON_THROW_ON_ERROR)); fclose($pipes[0]);
            $processes[]=[$process,$pipes];
        }
        $outcomes=[];
        foreach ($processes as [$process,$pipes]) {
            $outcomes[]=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0,proc_close($process),$errors); self::assertSame('',$errors);
        }
        sort($outcomes); return $outcomes;
    }

    public function testConcurrentRequestsConsumeTokenOnlyOnce(): void
    {
        $token=$this->c->tokens->issue('bootstrap',null);
        self::assertSame(['lost','won'],$this->compete(['mode'=>'token','token'=>$token['token']]));
        self::assertSame(1,(int)$this->c->db->run('SELECT count(*) FROM email_verification_tokens WHERE consumed_at IS NOT NULL')->fetchColumn());
    }

    public function testConcurrentRequestsAcceptTotpOnlyOnce(): void
    {
        $user=$this->account('admin@example.test','admin'); $secret=$this->c->mfa->newSecret();
        $this->c->mfa->enroll((int)$user['id'],$secret,TOTP::createFromSecret($secret,$this->clock)->now());
        $this->clock->advance(30);
        self::assertSame(['lost','won'],$this->compete(['mode'=>'mfa','user_id'=>(int)$user['id'],'code'=>TOTP::createFromSecret($secret,$this->clock)->now()]));
    }
}
