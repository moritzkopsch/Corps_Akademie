<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

final class InquiryRepository
{
    public function __construct(private readonly Database $db) {}

    public function submission(string $hash): ?array { return $this->db->one('SELECT * FROM public_inquiries WHERE submission_hash=?',[$hash]); }
    public function inquiry(string $id): ?array { return $this->db->one('SELECT i.*,s.title AS seminar_title FROM public_inquiries i LEFT JOIN seminars s ON s.id=i.seminar_id WHERE i.public_id=?',[$id]); }
    public function inquiryById(int $id): ?array { return $this->db->one('SELECT * FROM public_inquiries WHERE id=?',[$id]); }
    public function inquiries(): array { return $this->db->run("SELECT i.*,s.title AS seminar_title FROM public_inquiries i LEFT JOIN seminars s ON s.id=i.seminar_id WHERE i.state<>'unverified' ORDER BY i.created_at DESC LIMIT 100")->fetchAll(); }
    public function subscriber(string $emailKey): ?array { return $this->db->one('SELECT * FROM newsletter_subscribers WHERE email_key=?',[$emailKey]); }
    public function subscriberById(int $id): ?array { return $this->db->one('SELECT * FROM newsletter_subscribers WHERE id=?',[$id]); }

    public function insertInquiry(array $data): int
    {
        return (int)$this->db->run('INSERT INTO public_inquiries(public_id,submission_hash,kind,seminar_id,email,email_key,details_ciphertext,key_id,state,consent_version,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id',array_values($data))->fetchColumn();
    }

    public function activateInquiry(int $id, int $now): void { $this->db->run("UPDATE public_inquiries SET state='received',verified_at=?,updated_at=? WHERE id=? AND state='unverified'",[$now,$now,$id]); }
    public function updateState(int $id, string $state, int $now): void { $this->db->run("UPDATE public_inquiries SET state=?,updated_at=? WHERE id=? AND state<>'unverified'",[$state,$now,$id]); }

    public function requestSubscriber(string $email, string $emailKey, int $now, string $consent): int
    {
        $this->db->run("INSERT INTO newsletter_subscribers(email,email_key,state,consent_version,requested_at) VALUES(?,?,'pending',?,?) ON CONFLICT(email_key) DO UPDATE SET email=excluded.email,state='pending',consent_version=excluded.consent_version,requested_at=excluded.requested_at,subscription_version=newsletter_subscribers.subscription_version+1,confirmed_at=NULL,unsubscribed_at=NULL",[$email,$emailKey,$consent,$now]);
        return (int)$this->subscriber($emailKey)['id'];
    }

    public function confirmSubscriber(int $id, int $now): void { $this->db->run("UPDATE newsletter_subscribers SET state='active',confirmed_at=? WHERE id=? AND state='pending'",[$now,$id]); }
    public function unsubscribe(int $id, int $now): void { $this->db->run("UPDATE newsletter_subscribers SET state='unsubscribed',unsubscribed_at=? WHERE id=?",[$now,$id]); }

    public function issueToken(string $purpose, ?int $inquiry, ?int $subscriber, int $now, int $seconds = 1800): array
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Einmallinks benötigen eine Transaktion.'); }
        $this->db->run('UPDATE public_tokens SET revoked_at=? WHERE purpose=? AND inquiry_id IS NOT DISTINCT FROM ? AND subscriber_id IS NOT DISTINCT FROM ? AND consumed_at IS NULL AND revoked_at IS NULL',[$now,$purpose,$inquiry,$subscriber]);
        $token=Crypto::token();
        $id=(int)$this->db->run('INSERT INTO public_tokens(token_hash,purpose,inquiry_id,subscriber_id,expires_at,created_at) VALUES(?,?,?,?,?,?) RETURNING id',[Crypto::hash($token),$purpose,$inquiry,$subscriber,$now+$seconds,$now])->fetchColumn();
        return ['id'=>$id,'token'=>$token];
    }

    public function consume(string $purpose, string $token, int $now): array
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Tokenverbrauch benötigt eine Transaktion.'); }
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token)) { throw new \DomainException('Dieser Link ist ungültig oder abgelaufen.'); }
        // FOR UPDATE serialisiert konkurrierende Einlösungen desselben Einmallinks.
        $row=$this->db->one('SELECT * FROM public_tokens WHERE token_hash=? AND purpose=? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>? FOR UPDATE',[Crypto::hash($token),$purpose,$now]);
        if ($row===null) { throw new \DomainException('Dieser Link ist ungültig oder abgelaufen.'); }
        $this->db->run('UPDATE public_tokens SET consumed_at=? WHERE id=?',[$now,$row['id']]);
        return $row;
    }
}
