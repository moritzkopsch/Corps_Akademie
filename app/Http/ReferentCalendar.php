<?php
declare(strict_types=1);
namespace Corpsakademie\Http;

use Corpsakademie\Application\Calendar;
use Corpsakademie\Container;
use Symfony\Component\HttpFoundation\{Request,Response};

final class ReferentCalendar
{
    public function __construct(private readonly Container $c, private readonly \Closure $render) {}

    public function handle(Request $request,Session $session,array $user): Response
    {
        if (!$this->c->accounts->can((int)$user['id'],'portal.access')) {
            return ($this->render)('error.twig',$session,['status'=>403,'title'=>'Kein Zugriff','message'=>'Für diesen Bereich fehlt die Berechtigung.'],403);
        }
        $profile=$this->c->calendar->profileForUser((int)$user['id']);
        $values=$request->request->all(); $error=null; $notice=null;
        if ($request->isMethod('POST') && $profile!==null) {
            try {
                $action=is_string($values['action']??null)?$values['action']:'';
                if ($action==='window') { $this->c->calendar->saveWindow((int)$user['id'],$values); $notice='Der Zeitraum wurde gespeichert.'; }
                elseif ($action==='window-remove') { $this->c->calendar->removeWindow((int)$user['id'],$this->integer($values,'window_id')); $notice='Der Zeitraum wurde entfernt.'; }
                elseif ($action==='appointment') { $this->c->calendar->propose((int)$user['id'],$values); $notice='Der Terminvorschlag liegt jetzt bei der Verwaltung.'; }
                elseif ($action==='appointment-withdraw') { $this->c->calendar->withdraw((int)$user['id'],$this->integer($values,'appointment_id')); $notice='Der Terminvorschlag wurde zurückgezogen.'; }
                else { throw new \DomainException('Bitte eine gültige Aktion wählen.'); }
            } catch (\DomainException $exception) { $error=$exception->getMessage(); }
        }
        $referentId=$profile===null?null:(int)$profile['id'];
        return ($this->render)('portal-calendar.twig',$session,[
            'profile'=>$profile,'error'=>$error,'notice'=>$notice,'values'=>$request->isMethod('POST')?$values:[],
            'windows'=>$referentId===null?[]:$this->c->calendar->windows($referentId),
            'appointments'=>$referentId===null?[]:$this->c->calendar->appointments($referentId),
            'coverage'=>$referentId===null?[]:$this->c->calendar->coverage($referentId),
            'seminars'=>$referentId===null?[]:$this->c->catalog->seminars(true),
            'states'=>Calendar::STATES,'kinds'=>Calendar::KINDS,'today'=>$this->c->calendar->today(),'planning_months'=>Calendar::PLANNING_MONTHS,
        ],$error===null?200:422);
    }

    private function integer(array $values,string $key): int
    {
        $value=$values[$key]??null;
        return is_scalar($value)?(int)$value:0;
    }
}
