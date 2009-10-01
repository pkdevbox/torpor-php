# $Rev$
if [ -z "$PHP" ]; then
   PHP=`which php`
fi
(exec $PHP -C -q "$0" "$@")
if [ "$?" -ne "0" ]; then
    echo "FAILED:  Bad environment variable \$PHP (set to \"$PHP\")"
    exit 1
fi
exit 0
