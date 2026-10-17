<script setup lang="ts">
import { ArrowDown, ArrowRight, ArrowUpRight, BedDouble, Building2, Check, ChevronRight, Heart, MapPin, Menu, Ruler, ShieldCheck, UsersRound } from '@lucide/vue'
import { ref } from 'vue'

const menuOpen = ref(false)
const year = new Date().getFullYear()
</script>

<template>
  <main class="home3-page">
    <header class="home3-nav">
      <RouterLink to="/" class="home3-brand" aria-label="Exousia Realty home"><span class="home3-logo">E<i></i></span><span><strong>EXOUSIA</strong><small>REALTY</small></span></RouterLink>
      <button class="home3-menu" aria-label="Toggle navigation" @click="menuOpen = !menuOpen"><Menu :size="21" /></button>
      <nav :class="{ open: menuOpen }"><a href="#home3-work" @click="menuOpen = false">The workspace</a><a href="#home3-brokerage" @click="menuOpen = false">For brokerages</a><a href="#home3-security" @click="menuOpen = false">Built for trust</a><RouterLink to="/login" class="home3-login">Sign in <ArrowUpRight :size="15" /></RouterLink></nav>
    </header>

    <section class="home3-hero">
      <div class="home3-copy">
        <span class="home3-eyebrow"><i></i> THE REAL ESTATE BROKERAGE WORKSPACE</span>
        <h1>Every client.<br />Every property.<br /><em>One place to move.</em></h1>
        <p>Run your brokerage with a clear view of your leads, property listings, and deals—so your team can spend less time chasing details and more time finding the right home.</p>
        <div class="home3-actions"><RouterLink to="/register" class="home3-button">Bring your brokerage together <ArrowRight :size="17" /></RouterLink><RouterLink to="/login" class="home3-secondary">Already with us? Sign in</RouterLink></div>
        <div class="home3-trust"><span><ShieldCheck :size="17" /></span><div>One private workspace for your business<br /><strong>Company-scoped by design</strong></div></div>
      </div>

      <div class="home3-showcase" aria-label="Real estate listing with property search cards and a brokerage team">
        <div class="showcase-backdrop"></div><div class="showcase-grid"></div>
        <div class="showcase-topline"><span><i></i> EXOUSIA PROPERTY DESK</span><b>NEW CAIRO <MapPin :size="12" /></b></div>
        <div class="showcase-building">
          <div class="showcase-sky"><i class="showcase-sun"></i><i class="sky-cloud cloud-a"></i><i class="sky-cloud cloud-b"></i></div>
          <div class="showcase-tower tower-left"><div class="tower-roof"></div><div class="tower-windows"><i v-for="n in 18" :key="`l${n}`"></i></div><div class="tower-balcony"></div></div>
          <div class="showcase-tower tower-main"><div class="tower-roof"><span>GARDEN<br />RESIDENCES</span></div><div class="tower-windows"><i v-for="n in 28" :key="`m${n}`"></i></div><div class="tower-balcony"></div></div>
          <div class="showcase-tower tower-right"><div class="tower-roof"></div><div class="tower-windows"><i v-for="n in 20" :key="`r${n}`"></i></div><div class="tower-balcony"></div></div>
          <div class="showcase-landscape"><i class="landscape-tree tree-one"></i><i class="landscape-tree tree-two"></i><i class="landscape-tree tree-three"></i><div class="landscape-walk"></div><div class="landscape-gate">A PLACE TO CALL YOURS</div></div>
        </div>
        <div class="showcase-listing-card">
          <div class="showcase-card-heading"><span><i></i> FEATURED PROPERTY</span><button aria-label="Save listing"><Heart :size="15" /></button></div>
          <div class="showcase-card-title"><div><h2>Garden Residences</h2><span><MapPin :size="11" /> Fifth Settlement, New Cairo</span></div><b>FOR SALE</b></div>
          <div class="showcase-specs"><span><BedDouble :size="14" /><b>3</b> beds</span><span><Building2 :size="14" /><b>2</b> baths</span><span><Ruler :size="14" /><b>185</b> m²</span></div>
          <div class="showcase-card-bottom"><strong>EGP 8.4M <small>STARTING FROM</small></strong><button>View property <ChevronRight :size="14" /></button></div>
        </div>
        <div class="showcase-search-card"><div class="search-card-title"><span>YOUR PROPERTY MATCHES</span><b>12 <small>LISTINGS</small></b></div><div class="match-row"><span class="match-thumb thumb-one"><i></i></span><span><b>Lakeview District</b><small>New Cairo · Apartment</small></span><strong>EGP 6.2M</strong></div><div class="match-row"><span class="match-thumb thumb-two"><i></i></span><span><b>Hyde Park Living</b><small>New Cairo · Townhouse</small></span><strong>EGP 11.8M</strong></div><div class="match-row"><span class="match-thumb thumb-three"><i></i></span><span><b>Parkside Villas</b><small>Mostakbal City · Villa</small></span><strong>EGP 14.5M</strong></div><div class="match-card-footer"><span><i></i> MATCHED TO CLIENT PREFERENCES</span><ArrowRight :size="13" /></div></div>
        <div class="showcase-team-card"><span class="team-avatars"><i>O</i><i>M</i><i>S</i></span><span><b>Your brokerage</b><small>Working together</small></span><span class="team-online"><i></i> LIVE</span></div>
        <div class="showcase-stamp"><span><Check :size="14" /></span><b>VIEWING<br />CONFIRMED</b><small>Today · 4:30 PM</small></div>
        <div class="showcase-orbit orbit-left"></div><div class="showcase-orbit orbit-right"></div>
        <div class="showcase-base"><span>FIND THE RIGHT PLACE. BUILD THE RIGHT RELATIONSHIPS.</span><i></i><b>30.00° N</b></div>
      </div>
      <a href="#home3-work" class="home3-scroll">SEE HOW IT ALL COMES TOGETHER <i></i><ArrowDown :size="13" /></a>
    </section>

    <section id="home3-work" class="home3-intro"><div><span class="home3-eyebrow">BUILT FOR THE WAY A BROKERAGE ACTUALLY WORKS</span><h2>From the first enquiry<br />to the front door.</h2></div><p>Exousia is brokerage software made for the real work: looking after clients, managing property inventory, coordinating your team, and keeping deals moving.</p></section>

    <section class="home3-flow"><article><span class="flow-number">01</span><span class="flow-icon"><UsersRound :size="20" /></span><small>CLIENT RELATIONSHIPS</small><h3>Know every client.</h3><p>Keep enquiries, preferences, conversations, and follow-ups together. Give every client the attention they deserve.</p><div class="flow-visual clients-visual"><i>A</i><i>M</i><i>R</i><span>+24 CLIENTS</span></div></article><article><span class="flow-number">02</span><span class="flow-icon"><Building2 :size="20" /></span><small>PROPERTY INVENTORY</small><h3>See every property.</h3><p>Organize your listings, availability, prices, and property details in a searchable company inventory.</p><div class="flow-visual buildings-visual"><i></i><i></i><i></i><i></i><span>YOUR LISTINGS</span></div></article><article><span class="flow-number">03</span><span class="flow-icon"><ArrowRight :size="20" /></span><small>DEALS & TEAMWORK</small><h3>Move deals forward.</h3><p>Coordinate ownership and next steps across your brokerage, from an interested lead to a closed deal.</p><div class="flow-visual deal-visual"><i>NEW LEAD</i><b></b><i>VIEWING</i><b></b><i>DEAL</i></div></article></section>

    <section id="home3-brokerage" class="home3-brokerage"><div class="brokerage-art"><div class="brokerage-ring"></div><div class="brokerage-window"><span>EXOUSIA REALTY</span><i></i><b>THE RIGHT<br />SPACE TO GROW</b><small>CLIENTS · PROPERTIES · DEALS</small></div><div class="brokerage-floats float-a"><UsersRound :size="17" /><b>People, first.</b></div><div class="brokerage-floats float-b"><Building2 :size="17" /><b>Properties, in view.</b></div><div class="brokerage-floats float-c"><Check :size="17" /><b>Progress, together.</b></div></div><div class="brokerage-copy"><span class="home3-eyebrow">A CLEARER WAY TO RUN YOUR BROKERAGE</span><h2>More than a property list.<br /><em>Your whole business, connected.</em></h2><p>When lead follow-ups, property details, and deal progress live together, your agents can serve clients with confidence and your managers can see the business clearly.</p><ul><li><Check :size="15" /> A workspace for your brokerage</li><li><Check :size="15" /> Visibility shaped by team roles</li><li><Check :size="15" /> A useful history of important changes</li></ul><RouterLink to="/register" class="brokerage-link">Set up your workspace <ArrowRight :size="15" /></RouterLink></div></section>

    <section id="home3-security" class="home3-cta"><div class="home3-cta-glow"></div><span class="home3-eyebrow">YOUR BROKERAGE HAS PLACES TO GO</span><h2>Bring your people<br />and properties <em>together.</em></h2><p>One real estate workspace. A better view of every next step.</p><RouterLink to="/register" class="home3-button home3-cta-button">Create your brokerage workspace <ArrowRight :size="17" /></RouterLink><RouterLink to="/login" class="home3-cta-login">Already have an account? Sign in</RouterLink><div class="home3-city"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div></section>

    <footer class="home3-footer"><RouterLink to="/" class="home3-brand"><span class="home3-logo">E<i></i></span><span><strong>EXOUSIA</strong><small>REALTY</small></span></RouterLink><span>The real estate brokerage workspace.</span><small>© {{ year }} Exousia Realty</small></footer>
  </main>
</template>
