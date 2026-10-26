<?php
declare(strict_types=1);
namespace Corpsakademie\Http;

use Corpsakademie\Container;
use Corpsakademie\Infrastructure\Crypto;
use Symfony\Component\HttpFoundation\{RedirectResponse,Request,Response};

final class PublicWebsite
{
    public function __construct(private readonly Container $c, private readonly \Closure $render) {}

    public static function methods(string $path): ?array
    {
        if (in_array($path,['/','/ueber-uns','/seminare','/referenten','/termine','/faq','/impressum','/datenschutz'],true)) { return ['GET']; }
        if (in_array($path,['/anfrage','/kontakt','/newsletter','/anfrage/bestaetigen','/newsletter/bestaetigen','/newsletter/abmelden'],true)) { return ['GET','POST']; }
        if (preg_match('~^/(seminare|referenten)/[a-z0-9]+(?:-[a-z0-9]+)*$~D',$path)) { return ['GET']; }
        if (preg_match('~^/admin/(seminare|referenten)(?:/[a-z0-9]+(?:-[a-z0-9]+)*)?$~D',$path)) { return ['GET','POST']; }
        if (preg_match('~^/admin/newsletter(?:/(?:neu|[1-9][0-9]{0,9}))?$~D',$path)) { return ['GET','POST']; }
        if (preg_match('~^/admin/anfragen(?:/[A-Za-z0-9_-]{43})?$~D',$path)) { return ['GET','POST']; }
        return null;
    }

    public function public(Request $request, Session $session, string $ip): ?Response
    {
        $path=$request->getPathInfo();
        if (str_starts_with($path,'/admin/')) { return null; }
        if (self::methods($path)===null) { return null; }
        $context=['path'=>$path,'categories'=>$this->c->catalog->categories(),'meta_description'=>'Seminare für Corps, Aktive und Alte Herren. Entdecken Sie das Bildungsangebot der Corpsakademie und fragen Sie Ihr Seminar an.'];
        $show=fn(string $template,array $data=[],int $status=200): Response=>($this->render)('public/'.$template,$session,array_replace($context,$data),$status);
        if ($path==='/') { return $show('home.twig',['seminars'=>array_slice($this->c->catalog->seminars(),0,6)]); }
        if ($path==='/seminare') {
            $category=$this->query($request,'thema'); $search=mb_substr($this->query($request,'suche'),0,100);
            return $show('seminars.twig',['seminars'=>$this->c->catalog->seminars(true,$category,$search),'category_filter'=>$category,'search'=>$search]);
        }
        if (preg_match('~^/seminare/([a-z0-9-]+)$~D',$path,$matches)) {
            $seminar=$this->c->catalog->seminar($matches[1]);
            return $seminar===null?$show('notice.twig',['title'=>'Seminar nicht gefunden','message'=>'Dieses Seminar ist derzeit nicht veröffentlicht. Entdecken Sie unsere aktuellen Angebote.'],404):$show('seminar.twig',['seminar'=>$seminar,'referents'=>$this->c->catalog->referents(true,(int)$seminar['id']),'related'=>array_slice(array_values(array_filter($this->c->catalog->seminars(true,$seminar['category_slug']),fn(array $s): bool=>$s['id']!==$seminar['id'])),0,3),'meta_description'=>$seminar['summary']]);
        }
        if ($path==='/referenten') { return $show('referents.twig',['referents'=>$this->c->catalog->referents()]); }
        if (preg_match('~^/referenten/([a-z0-9-]+)$~D',$path,$matches)) {
            $referent=$this->c->catalog->referent($matches[1]);
            if ($referent===null) { return $show('notice.twig',['title'=>'Profil nicht gefunden','message'=>'Dieses Referentenprofil ist derzeit nicht veröffentlicht.'],404); }
            $seminars=array_filter($this->c->catalog->seminars(),function(array $seminar) use ($referent): bool { return in_array($referent['id'],array_column($this->c->catalog->referents(true,(int)$seminar['id']),'id'),true); });
            return $show('referent.twig',['referent'=>$referent,'seminars'=>$seminars]);
        }
        if (in_array($path,['/ueber-uns','/faq','/termine','/impressum','/datenschutz'],true)) { return $show('information.twig',['page'=>substr($path,1),'seminars'=>$path==='/termine'?$this->c->catalog->seminars():[]]); }
        if (!$this->c->config->mailEnabled && in_array($path,['/anfrage','/kontakt','/newsletter'],true)) {
            return $show('notice.twig',['title'=>'Wir richten die Online-Anfragen ein.','message'=>'Die Website und das Seminarangebot sind bereits verfügbar. Anfrageformulare und Newsletter werden nach Einrichtung des E-Mail-Versands freigeschaltet. Es wurden keine Angaben übermittelt.'],$request->isMethod('POST')?503:200);
        }
        $values=$this->values($request); $error=null;
        try {
            if (in_array($path,['/anfrage/bestaetigen','/newsletter/bestaetigen','/newsletter/abmelden'],true)) {
                if ($request->isMethod('POST')) {
                    match($path) {
                        '/anfrage/bestaetigen'=>$this->c->publicRequests->confirmInquiry($values['token'],$ip),
                        '/newsletter/bestaetigen'=>$this->c->publicRequests->confirmNewsletter($values['token'],$ip),
                        '/newsletter/abmelden'=>$this->c->publicRequests->unsubscribe($values['token'],$ip),
                    };
                    return $show('notice.twig',['title'=>match($path) {'/anfrage/bestaetigen'=>'Vielen Dank für Ihre Anfrage.','/newsletter/bestaetigen'=>'Willkommen im Verteiler.',default=>'Sie sind abgemeldet.'},'message'=>match($path) {'/anfrage/bestaetigen'=>'Ihre E-Mail-Adresse ist bestätigt. Die Corpsakademie kann Ihre Anfrage jetzt prüfen und die nächsten Schritte mit Ihnen abstimmen. Eine verbindliche Buchung erfolgt erst nach gesonderter Bestätigung.','/newsletter/bestaetigen'=>'Ihre Newsletter-Anmeldung ist bestätigt. Den Abmeldelink erhalten Sie per E-Mail.',default=>'Sie erhalten künftig keine Newsletter mehr an diese Adresse.'}]);
                }
                return $show('confirm.twig',['confirmation'=>$path,'error'=>null]);
            }
            if ($request->isMethod('POST')) {
                if ($path==='/newsletter') { $this->c->publicRequests->subscribe($request->request->all(),$ip); }
                else { $this->c->publicRequests->submit($request->request->all(),$path==='/anfrage'?'seminar':'contact',$ip); }
                return $show('notice.twig',['title'=>'Bitte prüfen Sie Ihr Postfach.','message'=>'Wenn die Anmeldung oder Anfrage noch bestätigt werden muss, erhalten Sie eine E-Mail mit einem Bestätigungslink. Erst nach Ihrer Bestätigung wird sie aktiviert. Der Link ist 30 Minuten gültig.']);
            }
        } catch (\DomainException $exception) { $error=$exception->getMessage(); }
        if (in_array($path,['/anfrage/bestaetigen','/newsletter/bestaetigen','/newsletter/abmelden'],true)) { return $show('confirm.twig',['confirmation'=>$path,'error'=>$error],422); }
        $seminars=$this->c->catalog->seminars();
        if (!$request->isMethod('POST') && $path==='/anfrage') { $values['seminar']=$this->query($request,'seminar'); }
        $selected=$values['seminar']===''?null:$this->c->catalog->seminar($values['seminar']);
        return $show('request.twig',['kind'=>match($path) {'/anfrage'=>'seminar','/kontakt'=>'contact',default=>'newsletter'},'values'=>$values,'seminars'=>$seminars,'selected'=>$selected,'referents'=>$selected===null?[]:$this->c->catalog->referents(true,(int)$selected['id']),'error'=>$error,'today'=>$this->c->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d')],$error===null?200:422);
    }

    public function admin(Request $request, Session $session, array $user): ?Response
    {
        $path=$request->getPathInfo();
        if (!str_starts_with($path,'/admin/') || self::methods($path)===null) { return null; }
        $inquiries=str_starts_with($path,'/admin/anfragen');
        if (!$this->c->accounts->can((int)$user['id'],$inquiries?'inquiries.manage':'content.manage')) { return ($this->render)('error.twig',$session,['status'=>403,'title'=>'Kein Zugriff','message'=>'Für diesen Verwaltungsbereich fehlt die Berechtigung.'],403); }
        $show=fn(array $data,int $status=200): Response=>($this->render)('content-admin.twig',$session,$data,$status);
        $segments=explode('/',trim($path,'/')); $section=$segments[1]; $slug=$segments[2]??null; $error=null;
        if ($inquiries) {
            $detail=$slug===null?null:$this->c->publicRequests->detailForAdmin((int)$user['id'],$slug);
            if ($slug!==null && $detail===null) { return $this->notFound($session); }
            if ($request->isMethod('POST')) {
                if ($detail===null) { return $this->notFound($session); }
                try { $this->c->publicRequests->updateState((int)$user['id'],$slug,$this->values($request)['state']); return new RedirectResponse($path,303); }
                catch (\DomainException $exception) { $error=$exception->getMessage(); }
            }
            return $show(['section'=>$section,'editing'=>false,'detail'=>$detail,'inquiries'=>$this->c->publicRequests->listForAdmin((int)$user['id']),'error'=>$error],$error===null?200:422);
        }
        $existing=$slug===null || $slug==='neu'?null:($section==='seminare'?$this->c->catalog->seminar($slug,false):$this->c->catalog->referent($slug,false));
        if ($slug!==null && $slug!=='neu' && $existing===null) { return $this->notFound($session); }
        if ($request->isMethod('POST') && $slug===null) { return $this->notFound($session); }
        if ($request->isMethod('POST')) {
            try {
                if ($section==='seminare') { $this->c->content->saveSeminar((int)$user['id'],$request->request->all(),$existing['slug']??null); }
                else { $this->c->content->saveReferent((int)$user['id'],$request->request->all(),$existing['slug']??null); }
                return new RedirectResponse('/admin/'.$section,303);
            } catch (\DomainException $exception) { $error=$exception->getMessage(); }
        }
        $values=$existing??[];
        if ($request->isMethod('POST')) { $values=$this->values($request)+$values; }
        return $show(['section'=>$section,'editing'=>$slug!==null,'values'=>$values,'error'=>$error,'categories'=>$this->c->catalog->categories(),'seminars'=>$this->c->catalog->seminars(false),'referents'=>$this->c->catalog->referents(false),'assigned'=>$existing===null?[]:array_column($this->c->catalog->referents(false,(int)$existing['id']),'id')],$error===null?200:422);
    }

    private function notFound(Session $session): Response { return ($this->render)('error.twig',$session,['status'=>404,'title'=>'Nicht gefunden','message'=>'Dieser Eintrag ist nicht verfügbar.'],404); }

    private function query(Request $request, string $key): string
    {
        $value=$request->query->all()[$key]??'';
        return is_string($value)?mb_substr($value,0,200):'';
    }

    private function values(Request $request): array
    {
        $values=array_fill_keys(['email','name','corps','role','phone','message','seminar','participants','date','location','format','token','state','slug','title','category_id','summary','description','audience','duration','status','featured','row_version','headline','biography','benefits','expertise'],'');
        $values['referent_mode']='recommend'; $values['submission']=Crypto::token(); $values['referents']=[];
        foreach ($request->request->all() as $key=>$value) {
            if ($key==='referents' && is_array($value)) { $values[$key]=array_values(array_filter($value,'is_string')); }
            elseif (is_string($value)) { $values[$key]=$value; }
        }
        return $values;
    }
}
