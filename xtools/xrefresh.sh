#!/bin/bash

video_hash_file='video_dir_hash.txt'

json_rpc_call() {
    if [ $1 ]; then
        cmd=`mktemp`
        req=`mktemp`
        resp=`mktemp`
        echo "{\"jsonrpc\": \"2.0\", \"method\": \"$1\"}" > "$cmd"

        size=`cat "$cmd" | wc -c`

        echo "POST /jsonrpc HTTP/1.0
User-Agent: xrefresh
Host: localhost
Content-Type: application/json
Content-length: $size
" > "$req"

        cat "$cmd" >> "$req"
        rm "$cmd"

        cat "$req" | nc localhost 80 > "$resp"
        rm "$req"
    fi
    return 0
}

while [ 0 ]; do
current_video_hash=`ls -R --ignore=*\.srt /home/pi/video/Series /home/pi/video/Films | md5sum | awk '{ print $1 }'`

if [ -f $video_hash_file ]; then
    stored_video_hash=`cat $video_hash_file`
    if [ $stored_video_hash != $current_video_hash ]; then
        echo "Two different hashes, need to refresh libs"
        if [[ $stored_video_hash =~ ":scan" ]]; then
            echo "Scaning library"
            json_rpc_call 'VideoLibrary.Scan'
            echo "$current_video_hash" > $video_hash_file
        else
            echo "Cleaning library"
            json_rpc_call 'VideoLibrary.Clean'
            echo "$current_video_hash:scan" > $video_hash_file
        fi

    # else
        # echo "No need to refresh libs"
    fi
else
    # echo "No video hash file found, creating one now"
    touch $video_hash_file
    echo $current_video_hash > $video_hash_file
fi

sleep 60
done
