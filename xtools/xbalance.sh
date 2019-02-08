#!/bin/bash

xmlrpc_call() {
    if [ $1 ]; then
        local cmd=`mktemp`
        local req=`mktemp`
        local resp=`mktemp`

        echo "<?xml version=\"1.0\"?>
<methodCall>
  <methodName>$1</methodName>
  <params>
    <param>
      <value>600</value>
    </param>
  </params>
</methodCall>" > "$cmd"


        local size=`cat "$cmd" | wc -c`

        echo "POST /jsonrpc HTTP/1.0
User-Agent: xbalance
Host: localhost
Content-Type: text/xml
Content-length: $size
" > "$req"

        cat "$cmd" >> "$req"
        rm "$cmd"

        cat "$req" | nc localhost 80 > "$resp"
        rm "$req"
        echo "$resp"
    fi
    return 0

}

json_rpc_call() {
    if [ $1 ]; then
        local cmd=`mktemp`
        local req=`mktemp`
        local resp=`mktemp`
        echo "{\"jsonrpc\": \"2.0\", \"method\": \"$1\", \"id\": 1}" > "$cmd"

        local size=`cat "$cmd" | wc -c`

        echo "POST /jsonrpc HTTP/1.0
User-Agent: xbalance
Host: localhost
Content-Type: application/json
Content-length: $size
" > "$req"

        cat "$cmd" >> "$req"
        rm "$cmd"

        cat "$req" | nc localhost 80 > "$resp"
        rm "$req"
        echo "$resp"
    fi
    return 0
}

while [ 0 ]; do
    #cat "$req" | nc localhost 80 > "$resp"
    resp=`json_rpc_call 'Player.GetActivePlayers'`

    json=`tail -n 1 "$resp"`
    echo $json
    players=`python -c "import json;print json.loads('$json').get('result');"`

    rtorrent_screen=`screen -list | grep rtorrent`
    if [ "$rtorrent_screen" == '' ]; then
        rtorrent=false
    else
        rtorrent=true
    fi

    if [ "$players" != '[]' ]; then
        type=`python -c "import json;type = json.loads('$json').get('result');print type[0].get('type');"`
        if [ "$type" == 'video' ] && $rtorrent ; then
            sudo /etc/init.d/rtorrent stop
        elif [ "$type" != 'video' ] && ! $rtorrent ; then
            sudo /etc/init.d/rtorrent start
        fi
    else
        if ! $rtorrent ; then
            sudo /etc/init.d/rtorrent start
        fi
    fi
    sleep 60
done
