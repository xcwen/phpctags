<?php
/**
 * @see \Illuminate\Routing\Router
 */
class Testb  extends Facade 
{
    /**
     * @return $this
     */

    public function get_v8(){
        //$this->v1
    }
    use Instance;

    use Concerns\InteractsWithContainer,
        Concerns\MakesHttpRequests,
        Concerns\ImpersonatesUsers;

}
