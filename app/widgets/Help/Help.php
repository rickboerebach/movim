<?php

class Help extends \Movim\Widget\Base
{
    function load()
    {
    }

    function ajaxAddChatroom()
    {
        $this->rpc(
            'MovimUtils.redirect',
            $this->route('chat', ['movim@conference.movim.eu', 'room'])
        );
    }
}

