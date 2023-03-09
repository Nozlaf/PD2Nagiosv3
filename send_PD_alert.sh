#!/usr/bin/env bash

set -o errexit
set -o pipefail
set -o nounset

# FOR USE WITH NAGIOS XI OR NAGIOS CORE
# Tested with the nagiosXI vmware image from nagios.com not tested on anything else at this stage
# tested with pdaltagent
# no warranty, if it breaks sorry I've only tested it once, well maybe twice now

#SERVICE COMMAND DEFINITION
#$USER1$/send_PD_alert.sh  -k $CONTACTPAGER$ -o "$HOSTNAME$" -s "$SERVICEDESC$" -t "$SERVICESTATE$" -f SERVICEDESC='"$SERVICEDESC$"' -f SERVICESTATE="$SERVICESTATE$" -f SERVICEOUTPUT='"$SERVICEOUTPUT$"' -f HOSTNAME="$HOSTNAME$" -f HOSTSTATE="$HOSTSTATE$" -f HOSTDISPLAYNAME="$HOSTDISPLAYNAME$" -f SERVICEEVENTID="$SERVICEEVENTID$" -f SERVICEOUTPUT='"$SERVICEOUTPUT$"' -i '"$HOSTNAME$_$SERVICEDESC$"'

#HOST COMMAND DEFINITION
#$USER1$/send_PD_alert.sh  -k $CONTACTPAGER$ -o "$HOSTNAME$" -t "$HOSTSTATE$" -f HOSTNAME="$HOSTNAME$" -f HOSTSTATE="$HOSTSTATE$" -f HOSTDISPLAYNAME="$HOSTDISPLAYNAME$" -f HOSTPROBLEMID="$HOSTPROBLEMID$" -f HOSTEVENTID="$HOSTEVENTID" -i "$HOSTNAME$"
severity="critical"
d_arg=""
f_arg=""
servicename=""
while [[ "$#" -gt 0 ]]; do
  case $1 in
    -k)
      k_arg="$2"
      shift
      ;;
    -d)
      d_arg="$2"
      shift
      ;;
    -c)
      c_arg="$2"
      shift
      ;;
    -f)
      if [ -z "$f_arg" ]; then
        f_arg="-f $2"
      else
        f_arg="$f_arg -f $2"
      fi
      shift
      ;;
    -t)
      case $2 in
        DOWN|PROBLEM|CRITICAL)
          t_arg="-t trigger"
          severity="critical"
          ;;
        UP|RECOVERY|OK)
          t_arg="-t resolve"
          ;;
        WARNING)
          t_arg="-t trigger"
          severity="warning"
          ;;
        *)
          t_arg="-t $2"
          ;;
      esac
      shift
      ;;
    -i)
      i_arg="-i $2"
      shift
      ;;
    -s)
      servicename="$2"
      shift
      ;;
    -o)
      hostname="$2"
      shift
      ;;
    -h|--help)
      echo "Usage: $0 [-k <arg>] [-d <arg>] [-c <arg>] [-f <arg>]... [-t <arg>] [-n <arg>] [-s <arg>]" >&2
      echo "  -k                Routing Key (Contact pager)"
      echo "  -d                Description (Summary)"
      echo "  -c                Client"
      echo "  -f                Custom field"
      echo "  -t                Notification type (UP, DOWN, PROBLEM, RECOVERY, WARNING, CRITICAL, etc.)"
      echo "  -i                Nagios Incident Key"
      echo "  -s                Service name"
      echo "  -o                Host name"
      exit 1
      ;;
    *)
      echo "Unknown parameter passed: $1" >&2
      exit 1
      ;;
  esac
  shift
done

if [ -z "$servicename"]
then
  description="${severity} issue with ${hostname} ${d_arg} "
else
  description="${severity} issue with ${servicename} on ${hostname} ${d_arg}"

fi
#for debugging uncomment this
#echo "docker exec pdaltagent_pdagentd pd-send -k $k_arg $t_arg ${severity:+-s \"$severity\"} ${description:+-d \'\"$description\"\'} ${client:+-c \"$client\"} ${custom_field:+-f \"$custom_field\"} ${notify_host:+-n host} $f_arg $i_arg \n" >> /tmp/sendpdevent.log

bash -c "docker exec pdaltagent_pdagentd pd-send -k $k_arg $t_arg ${severity:+-s \"$severity\"} ${description:+-d \'\"$description\"\'} ${client:+-c \"$client\"} ${custom_field:+-f \"$custom_field\"} ${notify_host:+-n host} $f_arg $i_arg"
