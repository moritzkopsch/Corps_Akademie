<?php
declare(strict_types=1);
namespace Corpsakademie\Tests;

use Corpsakademie\Infrastructure\{Config,Crypto,Migrator};
use Corpsakademie\Tests\Support\Fixture;

final class InfrastructureTest extends Fixture
{
    public function testSupportedPhpPatchBoundariesRejectUnpatchedMinorBranches(): void
    {
        self::assertFalse(Config::runtimeSupported('8.4.26'));
        self::assertTrue(Config::runtimeSupported('8.4.27'));
        self::assertFalse(Config::runtimeSupported('8.5.3'));
        self::assertTrue(Config::runtimeSupported('8.5.12'));
        self::assertTrue(Config::runtimeSupported('8.5.13'));
        self::assertFalse(Config::runtimeSupported('8.6.0'));
    }

    public function testFreshMigrationIsIdempotentAndCreatesNoDefaultUser(): void
    {
        self::assertSame([], (new Migrator($this->c->db,$this->root.'/database/migrations'))->migrate());
        self::assertSame(1,(int)$this->c->db->run('SELECT count(*) FROM schema_migrations')->fetchColumn());
        self::assertSame(0,(int)$this->c->db->run('SELECT count(*) FROM users')->fetchColumn());
        self::assertSame('wal',$this->c->db->pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(1,(int)$this->c->db->pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function testMigrationTamperingIsRejected(): void
    {
        $directory=$this->directory.'/migrations'; mkdir($directory);
        file_put_contents($directory.'/001_identity.sql',file_get_contents($this->root.'/database/migrations/001_identity.sql')."\n-- modified\n");
        $this->expectException(\RuntimeException::class);
        (new Migrator($this->c->db,$directory))->migrate();
    }

    public function testBackupIsConsistentAndUpgradeCreatesBackup(): void
    {
        $user=$this->account();
        $backup=$this->c->db->backup();
        $copy=new \PDO('sqlite:'.$backup);
        self::assertSame($user['email'],$copy->query('SELECT email FROM users')->fetchColumn());
        self::assertSame('ok',$copy->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame(0600,fileperms($backup)&0777);
        $directory=$this->directory.'/migrations'; mkdir($directory);
        copy($this->root.'/database/migrations/001_identity.sql',$directory.'/001_identity.sql');
        file_put_contents($directory.'/002_test.sql','CREATE TABLE upgrade_probe(id INTEGER PRIMARY KEY);');
        self::assertSame(['002_test.sql'],(new Migrator($this->c->db,$directory))->migrate());
        self::assertCount(2,glob($this->c->config->storagePath.'/backups/*.sqlite'));
    }

    public function testDatabaseAndStorageCannotBePublic(): void
    {
        $this->expectException(\RuntimeException::class);
        new Config($this->root,$this->values(['APP_DB_PATH'=>$this->root.'/public/probe.sqlite']));
    }

    public function testProductionRejectsHttpAndUnencryptedSmtp(): void
    {
        try {
            new Config($this->root,$this->values(['APP_ENV'=>'production']));
            self::fail('Production accepted HTTP');
        } catch (\RuntimeException $error) { self::assertStringContainsString('HTTPS',$error->getMessage()); }
        $this->expectException(\RuntimeException::class);
        new Config($this->root,$this->values(['APP_ENV'=>'production','APP_URL'=>'https://akademie.example.test','MAILER_DSN'=>'smtp://mail.example.test:587?fake=require_tls=true']));
    }

    public function testLocalModeRejectsExternalSmtp(): void
    {
        $this->expectException(\RuntimeException::class);
        new Config($this->root,$this->values(['MAILER_DSN'=>'smtp://mail.example.test:587']));
    }

    public function testEncryptionDetectsTamperingAndPurposeConfusion(): void
    {
        $encrypted=$this->c->crypto->encrypt('private value','session');
        self::assertStringNotContainsString('private value',$encrypted);
        self::assertSame('private value',$this->c->crypto->decrypt($encrypted,'session','v1'));
        $this->expectException(\RuntimeException::class);
        $this->c->crypto->decrypt($encrypted,'mfa','v1');
    }

    public function testTransactionRollsBackAllWrites(): void
    {
        try {
            $this->c->db->immediate(function(): void {
                $this->c->tokens->issue('bootstrap',null);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {}
        self::assertSame(0,(int)$this->c->db->run('SELECT count(*) FROM email_verification_tokens')->fetchColumn());
    }
}
