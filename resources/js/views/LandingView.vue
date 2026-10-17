<script setup lang="ts">
import { ArrowDown, ArrowRight, ArrowUpRight, Building2, Check, Menu, ShieldCheck, Sparkles } from '@lucide/vue'
import { ref } from 'vue'

const menuOpen = ref(false)
const year = new Date().getFullYear()
</script>

<template>
  <main class="landing-page">
    <header class="landing-nav">
      <RouterLink to="/" class="landing-brand" aria-label="Exousia Realty home">
        <span class="brand-symbol">E<span></span></span>
        <span><strong>EXOUSIA</strong><small>REALTY</small></span>
      </RouterLink>
      <button class="landing-menu-toggle" aria-label="Toggle navigation" @click="menuOpen = !menuOpen"><Menu :size="21" /></button>
      <nav :class="{ open: menuOpen }">
        <a href="#platform" @click="menuOpen = false">Platform</a>
        <a href="#how-it-works" @click="menuOpen = false">How it works</a>
        <a href="#property-matching" @click="menuOpen = false">Property matching</a>
        <a href="#for-teams" @click="menuOpen = false">For your team</a>
        <RouterLink to="/plans" @click="menuOpen = false">Plans</RouterLink>
        <RouterLink to="/login" class="nav-sign-in">Sign in <ArrowUpRight :size="15" /></RouterLink>
      </nav>
    </header>

    <section class="landing-hero">
      <div class="hero-copy">
        <span class="landing-eyebrow"><i></i> THE REAL ESTATE WORKSPACE, REIMAGINED</span>
        <h1>Every move brings<br />you <em>closer to home.</em></h1>
        <p class="hero-intro">From the first hello to the keys in hand, give your real estate team one beautiful place to make every opportunity count.</p>
        <div class="hero-actions">
          <RouterLink to="/register" class="landing-button">Build your workspace <ArrowRight :size="17" /></RouterLink>
          <a href="#platform" class="hero-text-link">Explore the platform <ArrowDown :size="15" /></a>
        </div>
        <div class="hero-proof"><span class="proof-icons"><i>R</i><i>C</i><i>+</i></span><span>Made for the people who make<br /><strong>every place feel like home.</strong></span></div>
      </div>

      <div class="hero-visual" aria-label="An animated crane building a city of homes">
        <div class="visual-orbit orbit-one"></div><div class="visual-orbit orbit-two"></div>
        <div class="visual-label label-top"><span class="label-dot"></span> YOUR NEXT CHAPTER <b>01 / 03</b></div>
        <div class="skyline-back"><i></i><i></i><i></i><i></i><i></i></div>
        <div class="hero-building">
          <div class="building-cap"><span>EXOUSIA RESIDENCES</span><i></i></div>
          <div class="building-floors">
            <div class="floor"><i></i><i></i><i></i><b>08</b></div>
            <div class="floor"><i></i><i></i><i></i><b>07</b></div>
            <div class="floor"><i></i><i></i><i></i><b>06</b></div>
            <div class="floor"><i></i><i></i><i></i><b>05</b></div>
            <div class="floor"><i></i><i></i><i></i><b>04</b></div>
            <div class="floor"><i></i><i></i><i></i><b>03</b></div>
            <div class="floor"><i></i><i></i><i></i><b>02</b></div>
            <div class="floor"><i></i><i></i><i></i><b>01</b></div>
          </div>
          <div class="building-podium"><span>THE ART OF ARRIVING</span><i></i><i></i><i></i></div>
        </div>
        <div class="crane">
          <div class="crane-mast"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
          <div class="crane-base"></div><div class="crane-jib"><span class="crane-cabin"></span><i class="crane-cable"></i><span class="crane-hook"></span><span class="jib-counterweight"></span></div>
        </div>
        <div class="floating-card unit-card"><span class="unit-icon"><Building2 :size="18" /></span><span><small>JUST PLACED</small><strong>Unit 08 · A new beginning</strong></span><i><Check :size="13" /></i></div>
        <div class="floating-card growth-card"><span class="growth-spark"><Sparkles :size="15" /></span><span><small>YOUR PIPELINE</small><strong>Moving beautifully</strong></span><span class="growth-bars"><i></i><i></i><i></i><i></i><i></i><i></i></span></div>
        <div class="visual-label label-bottom"><span>CAIRO, EGYPT</span><i></i><b>BUILT ON TRUST</b></div>
        <div class="hero-ground"></div>
      </div>
      <a href="#platform" class="scroll-cue"><span>SCROLL TO DISCOVER</span><i></i></a>
    </section>

    <section id="platform" class="landing-intro">
      <div class="intro-heading"><span class="landing-eyebrow">A BETTER WAY TO BUILD MOMENTUM</span><h2>Your business has a lot<br />of moving parts. <em>Now they move together.</em></h2></div>
      <p>Good relationships build great real estate businesses. Exousia gives every conversation, property, follow-up, and deal a place to move forward—together.</p>
    </section>

    <section id="how-it-works" class="landing-pillars">
      <article><span class="pillar-number">01</span><span class="pillar-icon"><Building2 :size="22" /></span><h3>See the whole picture.</h3><p>Keep leads, listings, and live deals in one clear view, so the next move always feels obvious.</p><a href="/register">Explore your workspace <ArrowUpRight :size="15" /></a></article>
      <article><span class="pillar-number">02</span><span class="pillar-icon"><ArrowRight :size="22" /></span><h3>Keep every promise.</h3><p>Thoughtful tasks and follow-ups help your team show up right when it matters most.</p><a href="/register">Make room for momentum <ArrowUpRight :size="15" /></a></article>
      <article id="for-teams"><span class="pillar-number">03</span><span class="pillar-icon"><ShieldCheck :size="22" /></span><h3>Grow with confidence.</h3><p>Company workspaces keep information in its right place, with clear roles and useful history.</p><a href="/register">Build with confidence <ArrowUpRight :size="15" /></a></article>
    </section>

    <section id="property-matching" class="landing-matching">
      <div class="matching-copy">
        <span class="landing-eyebrow"><i></i> THE RIGHT PLACE, FOUND FASTER</span>
        <h2>Bring the right<br />people to the <em>right place.</em></h2>
        <p>Connect what buyers are looking for with the homes your team knows best. Keep every listing, preference, and promising match moving in one view.</p>
        <div class="matching-points">
          <span><i><Check :size="13" /></i> A clear view of your inventory</span>
          <span><i><Check :size="13" /></i> Buyer needs beside every opportunity</span>
          <span><i><Check :size="13" /></i> Follow-ups that keep the search moving</span>
        </div>
        <RouterLink to="/register" class="matching-link">See how it works <ArrowRight :size="16" /></RouterLink>
      </div>
      <div class="matching-visual" role="img" aria-label="Three dimensional property cards connected to a buyer's home search">
        <div class="match-halo halo-back"></div><div class="match-halo halo-front"></div><div class="match-grid"></div>
        <div class="property-stack">
          <article class="property-tile tile-back"><div class="tile-scene scene-villa"><i></i><b></b><span></span></div><small>NEW CAIRO · VILLA</small><strong>A quieter kind of luxury</strong></article>
          <article class="property-tile tile-mid"><div class="tile-scene scene-loft"><i></i><b></b><span></span></div><small>MAADI · APARTMENT</small><strong>Room to make it yours</strong></article>
          <article class="property-tile tile-front"><div class="tile-scene scene-residence"><i></i><b></b><span></span><em></em></div><small>5TH SETTLEMENT · APARTMENT</small><strong>A bright new beginning</strong><div class="tile-price"><span>FROM</span><b>EGP 4.8M</b></div></article>
        </div>
        <div class="match-path path-one"></div><div class="match-path path-two"></div>
        <div class="buyer-card"><span class="buyer-avatar">M</span><span><small>BUYER SEARCH</small><strong>Mariam's next chapter</strong><i>2–3 bedrooms · New Cairo</i></span><b class="match-score">96%<small>MATCH</small></b></div>
        <div class="match-tag tag-budget"><i></i> Within budget</div><div class="match-tag tag-shortlist"><Check :size="11" /> Added to shortlist</div>
        <div class="matching-caption"><span>CURATED FOR THEIR NEXT MOVE</span><i></i><b>01 — 03</b></div>
      </div>
    </section>

    <section class="landing-cta"><div class="cta-glow"></div><span class="landing-eyebrow">THE NEXT MOVE IS YOURS</span><h2>Let’s make room<br />for <em>what’s next.</em></h2><p>Your people. Your properties. One place to bring it all together.</p><RouterLink to="/register" class="landing-button light-button">Create your workspace <ArrowRight :size="17" /></RouterLink><RouterLink to="/login" class="cta-login">Already have a workspace? Sign in</RouterLink><div class="cta-lines"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div></section>

    <footer class="landing-footer"><RouterLink to="/" class="landing-brand"><span class="brand-symbol">E<span></span></span><span><strong>EXOUSIA</strong><small>REALTY</small></span></RouterLink><span>Made for the people who bring people home.</span><small>© {{ year }} Exousia Realty</small></footer>
  </main>
</template>
