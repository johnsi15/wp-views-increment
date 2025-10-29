#!/bin/bash

# Simular 100 vistas de diferentes posts
for i in {1..100}
do
  POST_ID=$((1 + $RANDOM % 50))  # Posts del 1 al 50
  curl -s -X POST https://palegoldenrod-hedgehog-368669.hostingersite.com/wp-json/wpb/v1/increment-view \
    -H "Content-Type: application/json" \
    -d "{\"post_id\": $POST_ID}" &
  
  if [ $((i % 10)) -eq 0 ]; then
    echo "Enviadas $i vistas..."
    sleep 1
  fi
done

wait
echo "Test completado. Verifica el buffer en /wp-json/wpb/v1/status"