<?php
declare(strict_types=1);
namespace Corpsakademie\Http;

use Corpsakademie\Container;
use Symfony\Component\HttpFoundation\{Request,Response,RedirectResponse};

final class NewsletterAdmin
{
    public function __construct(private readonly Container $c,private readonly \Closure $render) {}

    public function handle(Request $request,Session $session,array $user): Response
    {
        if (!$this->c->accounts->can((int)$user['id'],'newsletter.manage')) {
            return ($this->render)('error.twig',$session,['status'=>403,'title'=>'Kein Zugriff','message'=>'Für die Newsletter-Verwaltung fehlt die Berechtigung.'],403);
        }
        $part=explode('/',trim($request->getPathInfo(),'/'))[2]??null;
        $id=$part===null || $part==='neu'?null:(int)$part;
        $campaign=$id===null?null:$this->c->newsletter->campaign((int)$user['id'],$id);
        if ($id!==null && $campaign===null) { return ($this->render)('error.twig',$session,['status'=>404,'title'=>'Nicht gefunden','message'=>'Diese Rundmail ist nicht verfügbar.'],404); }
        $error=null;
        $values=$campaign??['subject'=>'','body'=>'','row_version'=>0];
        if ($request->isMethod('POST')) {
            if ($part===null) { return new RedirectResponse('/admin/newsletter',303); }
            $data=$request->request->all(); $action=is_string($data['action']??null)?$data['action']:'';
            try {
                if ($action==='save') { $id=$this->c->newsletter->save((int)$user['id'],$data,$id); }
                elseif ($action==='queue' && $id!==null) {
                    $version=filter_var($data['row_version']??null,FILTER_VALIDATE_INT);
                    $this->c->newsletter->queue((int)$user['id'],$id,$version===false?0:$version,($data['confirm']??null)==='1');
                } elseif ($action==='cancel' && $id!==null) { $this->c->newsletter->cancel((int)$user['id'],$id); }
                else { throw new \DomainException('Bitte eine gültige Aktion wählen.'); }
                return new RedirectResponse('/admin/newsletter/'.$id,303);
            } catch (\DomainException $exception) {
                $error=$exception->getMessage();
                if ($action==='save') { foreach (['subject','body','row_version'] as $key) { if (is_string($data[$key]??null)) { $values[$key]=$data[$key]; } } }
            }
        }
        return ($this->render)('newsletter-admin.twig',$session,array_merge($this->c->newsletter->overview((int)$user['id']),[
            'editing'=>$part!==null,'campaign'=>$campaign,'values'=>$values,'error'=>$error,'newsletter_from'=>$this->c->config->newsletterFrom,'reply_to'=>$this->c->config->mailReplyTo,
        ]),$error===null?200:422);
    }
}
