<?php
declare(strict_types=1);
namespace Corpsakademie\Http;

use Corpsakademie\Application\Calendar;
use Corpsakademie\Container;
use Symfony\Component\HttpFoundation\{Request,Response};

final class CalendarAdmin
{
    public function __construct(private readonly Container $c, private readonly \Closure $render) {}

    public function handle(Request $request,Session $session,array $user): Response
    {
        if (!$this->c->accounts->can((int)$user['id'],'calendar.manage')) {
            return ($this->render)('error.twig',$session,['status'=>403,'title'=>'Kein Zugriff','message'=>'Für diesen Bereich fehlt die Berechtigung.'],403);
        }
        $values=$request->request->all(); $error=null; $notice=null;
        if ($request->isMethod('POST')) {
            try {
                $action=is_string($values['action']??null)?$values['action']:'';
                if ($action==='decide') {
                    $this->c->calendar->decide((int)$user['id'],$this->integer($values,'appointment_id'),is_string($values['state']??null)?$values['state']:'');
                    $notice='Die Entscheidung wurde gespeichert.';
                } elseif ($action==='plan') {
                    $this->c->calendar->plan((int)$user['id'],$this->integer($values,'referent_id'),$values);
                    $notice='Der Termin wurde eingetragen.';
                } else { throw new \DomainException('Bitte eine gültige Aktion wählen.'); }
            } catch (\DomainException $exception) { $error=$exception->getMessage(); }
        }
        $board=$this->c->calendar->board((int)$user['id']);
        $selected=filter_var($request->query->get('referent'),FILTER_VALIDATE_INT);
        return ($this->render)('calendar-admin.twig',$session,[
            'error'=>$error,'notice'=>$notice,'values'=>$request->isMethod('POST')?$values:[],
            'pending'=>$board['pending'],'decided'=>$board['decided'],'referents'=>$board['referents'],
            'detail'=>$selected===false?null:$this->c->calendar->detail((int)$user['id'],$selected),
            'seminars'=>$this->c->catalog->seminars(false),
            'states'=>Calendar::STATES,'today'=>$this->c->calendar->today(),'planning_months'=>Calendar::PLANNING_MONTHS,
        ],$error===null?200:422);
    }

    private function integer(array $values,string $key): int
    {
        $value=$values[$key]??null;
        return is_scalar($value)?(int)$value:0;
    }
}
