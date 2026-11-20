# Packagist minifies p2 metadata: a key equal to the one of the previous release is left out, and
# "__unset" removes it.
def expanded_releases($name):
  [foreach .packages[$name][] as $release ({}; (. + $release) | with_entries(select(.value != "__unset")); .)];

def epoch: sub("\\+00:00$"; "Z") | fromdateiso8601;
