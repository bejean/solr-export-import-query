Exemple de commande pour extraire des requetes d'un log solr

sed -E 's/^(.{23}).+\[(.+)\]  webapp=.+params=.(.+). .+/\1 \2 \3/' select.log
