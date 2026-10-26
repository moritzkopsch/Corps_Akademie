<?php
declare(strict_types=1);
namespace Corpsakademie\Http;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Corpsakademie\Container;
use Corpsakademie\Infrastructure\Crypto;
use Symfony\Component\HttpFoundation\{RedirectResponse,Request,Response};
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class Application
{
    private readonly Environment $views;
    private const ROUTES = [
        '/' => ['GET'], '/anmelden'=>['GET','POST'], '/abmelden'=>['POST'],
        '/einrichten'=>['GET','POST'], '/email-bestaetigung'=>['GET','POST'], '/email-bestaetigen'=>['GET','POST'],
        '/passwort-vergessen'=>['GET','POST'], '/passwort-neu'=>['GET','POST'],
        '/sicherheit/einrichten'=>['GET','POST'], '/sicherheit/code'=>['GET','POST'],
        '/sicherheit/wiederherstellung'=>['GET'], '/konto'=>['GET'], '/admin'=>['GET'],
        '/admin/benutzer'=>['GET'], '/portal'=>['GET'],
    ];

    public function __construct(private readonly Container $c)
    {
        $this->views = new Environment(new FilesystemLoader($c->config->root.'/resources/views'),[
            'autoescape'=>'html', 'strict_variables'=>true, 'cache'=>false,
        ]);
    }

    public function handle(Request $request): Response
    {
        $session = null;
        try {
            $hosts = [parse_url($this->c->config->url,PHP_URL_HOST)];
            if ($this->c->config->environment !== 'production') { $hosts = array_merge($hosts,['127.0.0.1','localhost']); }
            if (!in_array($request->getHost(),$hosts,true)) { return $this->headers($this->error(400,'Ungültiger Aufruf','Diese Adresse ist für die Anwendung nicht freigegeben.')); }
            if ($this->c->config->secureCookies() && !$request->isSecure()) { return $this->headers($this->error(400,'Sichere Verbindung erforderlich','Bitte verwende die HTTPS-Adresse der Anwendung.')); }
            $path = $request->getPathInfo();
            $methods = self::ROUTES[$path] ?? PublicWebsite::methods($path);
            if ($methods === null) { return $this->headers($this->error(404,'Seite nicht gefunden','Die gewünschte Seite ist nicht verfügbar.')); }
            $method = $request->getMethod();
            if (!in_array($method,$methods,true)) {
                $response = $this->error(405,'Aufruf nicht möglich','Diese Aktion ist mit diesem Aufruf nicht verfügbar.');
                $response->headers->set('Allow',implode(', ',$methods));
                return $this->headers($response);
            }
            if ((int)$request->headers->get('Content-Length','0')>32768) { return $this->headers($this->error(413,'Eingabe zu groß','Bitte kürze deine Eingaben.')); }
            $ip = $request->getClientIp() ?? 'unknown';
            if (!$this->c->limits->attempt('http.ip',$ip,120,60)) { return $this->headers($this->error(429,'Bitte kurz warten','Zu viele Aufrufe. Bitte in einer Minute erneut versuchen.')); }
            $session = $this->c->sessions->load($request);
            if ($method === 'POST') {
                $origin = $request->headers->get('Origin');
                if (($origin !== null && !hash_equals($request->getSchemeAndHttpHost(),rtrim($origin,'/')))
                    || !hash_equals($session->csrf(),$this->input($request,'_csrf'))) {
                    $this->c->audit->security('csrf.rejected',$this->c->crypto->subject('ip',$ip),$session->userId);
                    $response = $this->error(403,'Formular abgelaufen','Bitte öffne die Seite erneut und versuche es noch einmal.',$session);
                } else {
                    $response = $this->dispatch($request,$session,$ip);
                }
            } else {
                $response = $this->dispatch($request,$session,$ip);
            }
        } catch (\Throwable $error) {
            if ($this->c->config->environment==='test' && PHP_SAPI==='cli') { throw $error; }
            $reference = bin2hex(random_bytes(8));
            $directory = $this->c->config->storagePath.'/logs';
            if (!is_dir($directory)) { mkdir($directory,0700,true); }
            // Never log request data, URLs with tokens, SQL parameters or traces.
            file_put_contents($directory.'/application.log',json_encode([
                'time'=>gmdate(DATE_ATOM), 'reference'=>$reference, 'error_type'=>get_debug_type($error),
            ],JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND|LOCK_EX);
            $response = $this->error(500,'Das hat gerade nicht funktioniert','Bitte versuche es später erneut. Fehlerreferenz: '.$reference,$session);
        }
        if ($session !== null) { $this->c->sessions->save($session,$response); }
        return $this->headers($response);
    }

    private function dispatch(Request $request, Session &$session, string $ip): Response
    {
        $path = $request->getPathInfo();
        $post = $request->isMethod('POST');
        $user = $session->userId === null ? null : $this->c->accounts->find($session->userId);
        $values = ['email'=>$this->input($request,'email'),'name'=>$this->input($request,'name'),'token'=>$this->input($request,'token')];
        try {
            $website = new PublicWebsite($this->c,$this->view(...));
            $public = $website->public($request,$session,$ip);
            if ($public !== null) { return $public; }
            if (!$this->c->config->mailEnabled && in_array($path,['/einrichten','/passwort-vergessen','/email-bestaetigung'],true)) {
                return $this->error(503,'E-Mail-Versand wird eingerichtet','Kontoeinrichtung und Wiederherstellung werden nach Einrichtung des E-Mail-Versands freigeschaltet.',$session);
            }
            if ($path === '/abmelden') {
                if ($session->userId !== null) { $this->c->audit->record('session.logout',$session->userId); }
                $session = $this->c->sessions->rotate($session,null);
                return new RedirectResponse('/anmelden',303);
            }
            if ($path === '/anmelden') {
                if ($user !== null) { return new RedirectResponse($this->destination($session),303); }
                if ($post) {
                    $session = $this->c->auth->login($values['email'],$this->input($request,'password'),$ip,$session);
                    return new RedirectResponse($this->destination($session),303);
                }
                return $this->form('login',$session,$values);
            }
            if ($path === '/einrichten') {
                if (!$this->c->links->isUnconfigured()) { return $this->error(404,'Einrichtung abgeschlossen','Die Ersteinrichtung ist bereits abgeschlossen.',$session); }
                if ($post) {
                    if (!$this->c->limits->attempt('setup.ip',$ip,10,900)) { throw new \DomainException('Zu viele Versuche. Bitte später erneut versuchen.'); }
                    $this->matchingPasswords($request);
                    $created = $this->c->links->setup($values['token'],$values['email'],$values['name'],$this->input($request,'password'));
                    $session = $this->c->sessions->rotate($session,(int)$created['id']);
                    return new RedirectResponse('/email-bestaetigung',303);
                }
                return $this->form('setup',$session,$values);
            }
            if ($path === '/passwort-vergessen') {
                if ($post) {
                    $this->c->links->requestReset($values['email'],$ip);
                    return $this->view('message.twig',$session,['title'=>'Prüfe dein Postfach','message'=>'Falls für diese Adresse ein aktives Konto besteht, erhältst du einen Link zum Zurücksetzen deines Passworts.','action_url'=>'/anmelden','action_label'=>'Zur Anmeldung']);
                }
                return $this->form('forgot',$session,$values);
            }
            if ($path === '/passwort-neu') {
                if ($post) {
                    if (!$this->c->limits->attempt('reset.consume.ip',$ip,20,900)) { throw new \DomainException('Zu viele Versuche. Bitte später erneut versuchen.'); }
                    $this->matchingPasswords($request);
                    $this->c->links->reset($values['token'],$this->input($request,'password'));
                    $session = $this->c->sessions->rotate($session,null);
                    return $this->view('message.twig',$session,['title'=>'Passwort geändert','message'=>'Dein Passwort wurde geändert. Bitte melde dich mit dem neuen Passwort an.','action_url'=>'/anmelden','action_label'=>'Anmelden']);
                }
                return $this->form('reset',$session,$values);
            }
            if ($path === '/email-bestaetigen') {
                if ($post) {
                    if (!$this->c->limits->attempt('verify.consume.ip',$ip,20,900)) { throw new \DomainException('Zu viele Versuche. Bitte später erneut versuchen.'); }
                    $verified = $this->c->links->confirmEmail($values['token']);
                    if ($session->userId === (int)$verified['id']) {
                        $session = $this->c->sessions->rotate($session,(int)$verified['id']);
                        return new RedirectResponse($this->destination($session),303);
                    }
                    return $this->view('message.twig',$session,['title'=>'E-Mail-Adresse bestätigt','message'=>'Deine E-Mail-Adresse wurde bestätigt. Du kannst dich jetzt anmelden.','action_url'=>'/anmelden','action_label'=>'Anmelden']);
                }
                return $this->form('verify',$session,$values);
            }
            if ($user === null) { return new RedirectResponse('/anmelden',303); }
            if ($path === '/email-bestaetigung') {
                if ($user['email_verified_at'] !== null) { return new RedirectResponse($this->destination($session),303); }
                if ($post) { $this->c->links->resendVerification((int)$user['id'],$ip); }
                return $this->view('verify-pending.twig',$session,['resent'=>$post]);
            }
            if ($user['email_verified_at'] === null) { return new RedirectResponse('/email-bestaetigung',303); }
            if ($path === '/sicherheit/einrichten') {
                if ($this->c->mfa->factor((int)$user['id']) !== null) { return new RedirectResponse($this->destination($session),303); }
                $now = $this->c->clock->now()->getTimestamp();
                if (!isset($session->data['mfa_secret']) || ($session->data['mfa_secret_created']??0) < $now-600) {
                    $session->set('mfa_secret',$this->c->mfa->newSecret());
                    $session->set('mfa_secret_created',$now);
                    if ($post) { throw new \DomainException('Die Einrichtung ist abgelaufen. Bitte den neuen QR-Code scannen.'); }
                }
                if ($post) {
                    $this->mfaLimit($user,$ip);
                    $session = $this->c->db->immediate(function() use ($user,$session,$request): Session {
                        $codes = $this->c->mfa->enroll((int)$user['id'],$session->data['mfa_secret'],trim($this->input($request,'code')),Crypto::hash($session->token));
                        return $this->c->sessions->rotate($session,(int)$user['id'],['mfa_verified'=>true,'recovery_codes'=>$codes]);
                    });
                    return new RedirectResponse('/sicherheit/wiederherstellung',303);
                }
                return $this->enrollment($session,$user);
            }
            if ($path === '/sicherheit/code') {
                if ($this->isAuthenticated($session)) { return new RedirectResponse($this->destination($session),303); }
                if ($this->c->mfa->factor((int)$user['id']) === null) { return new RedirectResponse('/sicherheit/einrichten',303); }
                if ($post) {
                    $this->mfaLimit($user,$ip);
                    $session = $this->c->db->immediate(function() use ($user,$session,$request): Session {
                        if (!$this->c->mfa->verify((int)$user['id'],trim($this->input($request,'code')))) { throw new \DomainException('Der Code ist ungültig oder wurde bereits verwendet.'); }
                        $this->c->audit->record('login.mfa_verified',(int)$user['id'],$user['public_id']);
                        return $this->c->sessions->rotate($session,(int)$user['id'],['mfa_verified'=>true]);
                    });
                    return new RedirectResponse($this->destination($session),303);
                }
                return $this->form('mfa',$session,$values);
            }
            if (!$this->isAuthenticated($session)) { return new RedirectResponse($this->destination($session),303); }
            if (str_starts_with($path,'/admin/newsletter')) { return (new NewsletterAdmin($this->c,$this->view(...)))->handle($request,$session,$user); }
            $content = $website->admin($request,$session,$user);
            if ($content !== null) { return $content; }
            if ($path === '/sicherheit/wiederherstellung') {
                $codes = $session->take('recovery_codes');
                if (!is_array($codes)) { return new RedirectResponse('/konto',303); }
                return $this->view('recovery.twig',$session,['codes'=>$codes]);
            }
            if ($path === '/konto') { return $this->view('account.twig',$session,['mfa_enabled'=>$this->c->mfa->factor((int)$user['id'])!==null]); }
            if ($path === '/admin' || $path === '/admin/benutzer') {
                $permission = $path === '/admin' ? 'admin.access' : 'users.view';
                if (!$this->c->accounts->can((int)$user['id'],$permission)) { return $this->error(403,'Kein Zugriff','Für diesen Bereich fehlt dir die Berechtigung.',$session); }
                $users = $this->c->identity->overview();
                $stats = $this->c->identity->statistics();
                return $this->view('admin.twig',$session,['users'=>$users,'stats'=>$stats,'list_only'=>$path==='/admin/benutzer']);
            }
            if ($path === '/portal') {
                if (!$this->c->accounts->can((int)$user['id'],'portal.access')) { return $this->error(403,'Kein Zugriff','Für diesen Bereich fehlt dir die Berechtigung.',$session); }
                return $this->view('portal.twig',$session);
            }
            return $this->error(404,'Seite nicht gefunden','Die gewünschte Seite ist nicht verfügbar.',$session);
        } catch (\DomainException $error) {
            if (str_starts_with($path,'/sicherheit/')) { $this->c->audit->security('mfa.rejected',$this->c->crypto->subject('ip',$ip),$session->userId); }
            $kind = match($path) {
                '/anmelden'=>'login','/einrichten'=>'setup','/passwort-vergessen'=>'forgot',
                '/passwort-neu'=>'reset','/email-bestaetigen'=>'verify','/sicherheit/code'=>'mfa',default=>null,
            };
            if ($path==='/sicherheit/einrichten') { return $this->enrollment($session,$user,$error->getMessage()); }
            return $kind === null ? $this->error(422,'Bitte Eingabe prüfen',$error->getMessage(),$session) : $this->form($kind,$session,$values,$error->getMessage(),422);
        }
    }

    private function mfaLimit(array $user, string $ip): void
    {
        $a = $this->c->limits->attempt('mfa.user',(string)$user['id'],5,300);
        $b = $this->c->limits->attempt('mfa.ip',$ip,20,300);
        if (!$a || !$b) { throw new \DomainException('Zu viele Codeversuche. Bitte in fünf Minuten erneut versuchen.'); }
    }

    public function isAuthenticated(Session $session): bool
    {
        if ($session->userId===null) { return false; }
        $user = $this->c->accounts->find($session->userId);
        if ($user===null || $user['status']!=='active' || $user['email_verified_at']===null) { return false; }
        $factor = $this->c->mfa->factor($session->userId);
        if ($this->c->accounts->requiresMfa($session->userId) && $factor===null) { return false; }
        return $factor===null || ($session->data['mfa_verified']??false)===true;
    }

    private function destination(Session $session): string
    {
        if ($session->userId===null) { return '/anmelden'; }
        $user = $this->c->accounts->find($session->userId);
        if ($user['email_verified_at']===null) { return '/email-bestaetigung'; }
        $factor = $this->c->mfa->factor($session->userId);
        if ($factor===null && $this->c->accounts->requiresMfa($session->userId)) { return '/sicherheit/einrichten'; }
        if ($factor!==null && !($session->data['mfa_verified']??false)) { return '/sicherheit/code'; }
        return $this->c->accounts->can($session->userId,'admin.access') ? '/admin' : '/portal';
    }

    private function form(string $kind, Session $session, array $values, ?string $error=null, int $status=200): Response
    {
        return $this->view('form.twig',$session,['kind'=>$kind,'values'=>$values,'error'=>$error],$status);
    }

    private function enrollment(Session $session, array $user, ?string $error=null): Response
    {
        $uri = $this->c->mfa->uri($session->data['mfa_secret'],$user['email']);
        $writer = new Writer(new ImageRenderer(new RendererStyle(220),new SvgImageBackEnd()));
        return $this->view('enroll.twig',$session,['qr'=>'data:image/svg+xml;base64,'.base64_encode($writer->writeString($uri)),'secret'=>$session->data['mfa_secret'],'error'=>$error],$error===null?200:422);
    }

    private function matchingPasswords(Request $request): void
    {
        if (!hash_equals($this->input($request,'password'),$this->input($request,'password_confirm'))) { throw new \DomainException('Die beiden Passwörter stimmen nicht überein.'); }
    }

    private function input(Request $request, string $key): string
    {
        $value = $request->request->all()[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    private function error(int $status, string $title, string $message, ?Session $session=null): Response
    {
        return $this->view('error.twig',$session,['title'=>$title,'message'=>$message,'status'=>$status],$status);
    }

    private function view(string $template, ?Session $session, array $context=[], int $status=200): Response
    {
        $user = $session?->userId === null ? null : $this->c->accounts->find($session->userId);
        $profile = $user === null ? null : ['name'=>$user['name'],'email'=>$user['email'],'roles'=>$this->c->accounts->roles((int)$user['id'])];
        $full = $session!==null && $this->isAuthenticated($session);
        return new Response($this->views->render($template,array_replace([
            'user'=>$profile,'csrf'=>$session?->csrf(),'authenticated'=>$full,
            'can_admin'=>$full && $this->c->accounts->can((int)$user['id'],'admin.access'),
            'can_portal'=>$full && $this->c->accounts->can((int)$user['id'],'portal.access'),
            'is_local'=>$this->c->config->environment!=='production',
            'mail_enabled'=>$this->c->config->mailEnabled,
        ],$context)),$status,['Content-Type'=>'text/html; charset=UTF-8']);
    }

    private function headers(Response $response): Response
    {
        $response->headers->set('Cache-Control','no-store, private');
        $response->headers->set('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        // no-referrer makes browsers send Origin:null on ordinary form POSTs.
        // same-origin preserves the Origin check and sends no referrer externally.
        $response->headers->set('Referrer-Policy','same-origin');
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('X-Frame-Options','DENY');
        $response->headers->set('Permissions-Policy','camera=(), microphone=(), geolocation=()');
        if ($this->c->config->secureCookies()) { $response->headers->set('Strict-Transport-Security','max-age=31536000'); }
        return $response;
    }
}
