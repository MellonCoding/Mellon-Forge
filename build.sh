#!/bin/bash
# Netlify esegue questo script al posto di "npm run build"
# Inietta le variabili d'ambiente nel config.js e compila Tailwind

# Sostituisci i placeholder con i valori reali da Netlify Environment Variables
sed -i "s|YOUR_PROJECT_ID.supabase.co|${SUPABASE_URL#https://}|g" js/config.js
sed -i "s|YOUR_ANON_KEY|${SUPABASE_ANON_KEY}|g" js/config.js

# Compila Tailwind
npm install
npx tailwindcss -i ./css/input.css -o ./css/style.css --minify
