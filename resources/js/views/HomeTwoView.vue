<script setup lang="ts">
import { ArrowDown, ArrowRight, ArrowUpRight, Check, Compass, Heart, Menu, ShieldCheck, Sparkles, UsersRound } from '@lucide/vue'
import { ref } from 'vue'

const menuOpen = ref(false)
const year = new Date().getFullYear()
</script>

<template>
  <main class="home2-page">
    <header class="home2-nav">
      <RouterLink to="/" class="home2-brand" aria-label="Exousia Realty home"><span class="home2-mark">E<i></i></span><span><strong>EXOUSIA</strong><small>REALTY</small></span></RouterLink>
      <button class="home2-menu" aria-label="Toggle navigation" @click="menuOpen = !menuOpen"><Menu :size="21" /></button>
      <nav :class="{ open: menuOpen }"><a href="#home2-platform" @click="menuOpen = false">The experience</a><a href="#home2-journey" @click="menuOpen = false">Your workflow</a><a href="#home2-team" @click="menuOpen = false">For your team</a><RouterLink to="/login" class="home2-signin">Sign in <ArrowUpRight :size="15" /></RouterLink></nav>
    </header>

    <section class="home2-hero">
      <div class="home2-copy">
        <span class="home2-eyebrow"><i></i> FOR THE PEOPLE BEHIND EVERY PLACE</span>
        <h1>Good things<br />happen when<br /><em>everything connects.</em></h1>
        <p>Relationships are the heart of real estate. Bring your clients, properties, and team into one considered space—and let the right connections lead you home.</p>
        <div class="home2-actions"><RouterLink to="/register" class="home2-button">Find your flow <ArrowRight :size="17" /></RouterLink><a href="#home2-platform" class="home2-explore">Discover Exousia <ArrowDown :size="15" /></a></div>
        <div class="home2-note"><span><Heart :size="15" /></span><div>Every client has a story.<br /><strong>Help yours find its place.</strong></div></div>
      </div>

      <div class="home2-scene" aria-label="Animated property cards connecting a client, advisor, and home">
        <div class="home2-scene-wash"></div><div class="home2-ring ring-a"></div><div class="home2-ring ring-b"></div><div class="home2-ring ring-c"></div>
        <div class="home2-scene-caption"><span>THE RIGHT PLACE</span><i></i><b>THE RIGHT PEOPLE</b></div>
        <svg class="home2-paths" viewBox="0 0 620 540" fill="none" aria-hidden="true"><path class="path-glow" d="M128 281C145 166 254 101 352 151c94 48 116 142 155 200"/><path class="path-dash" d="M128 281C145 166 254 101 352 151c94 48 116 142 155 200"/><path class="path-glow path-second" d="M128 281c68 96 153 140 258 115 54-13 95-32 121-45"/><path class="path-dash path-second" d="M128 281c68 96 153 140 258 115 54-13 95-32 121-45"/></svg>
        <div class="home2-node node-person"><span class="person-halo"><span class="person-head"></span><span class="person-body"></span></span><b>YOUR CLIENT</b><small>Looking for home</small></div>
        <div class="home2-node node-agent"><span class="agent-halo"><span class="agent-head"></span><span class="agent-body"></span><i><Sparkles :size="13" /></i></span><b>YOUR ADVISOR</b><small>Always in the know</small></div>
        <div class="home2-listing listing-main"><div class="listing-photo photo-main"><span class="photo-sun"></span><i class="photo-building"></i><i class="photo-balcony"></i><i class="photo-plant"></i><span class="listing-tag"><i></i> A PLACE TO BELONG</span></div><div class="listing-info"><div><strong>Garden Residences</strong><small>New Cairo · A brighter kind of living</small></div><button aria-label="Save Garden Residences"><Heart :size="16" /></button></div><div class="listing-footer"><span>READY TO DISCOVER</span><b>01 <i>/ 12</i></b></div></div>
        <div class="home2-listing listing-mini"><div class="listing-photo photo-mini"><span class="mini-tower"></span><span class="mini-tree"></span></div><div class="mini-copy"><span>CURATED FOR YOU</span><strong>Somewhere<br />that feels right.</strong><i><ArrowUpRight :size="14" /></i></div></div>
        <div class="home2-pin"><span><Compass :size="19" /></span><i></i></div>
        <div class="home2-connect connect-one"><span><Heart :size="13" /></span> THE PERFECT MATCH</div>
        <div class="home2-connect connect-two"><span><Check :size="12" /></span> A FOLLOW-UP, RIGHT ON TIME</div>
        <div class="home2-scene-ground"></div>
        <div class="home2-coordinates"><span>30°00' N</span><i></i><b>CAIRO, EGYPT</b></div>
      </div>
      <a class="home2-scroll" href="#home2-platform">A MORE HUMAN WAY TO WORK <i></i></a>
    </section>

    <section id="home2-platform" class="home2-intro"><div><span class="home2-eyebrow">YOUR PEOPLE, YOUR PROPERTIES, YOUR MOMENT</span><h2>Real estate is personal.<br /><em>Your workspace should be, too.</em></h2></div><p>Exousia helps independent brokerages build trust at every turn. One connected workspace, thoughtfully made for the way your team works and the way your clients choose.</p></section>

    <section id="home2-journey" class="home2-journey"><div class="journey-heading"><span class="home2-eyebrow">A LITTLE MORE CLARITY. A LOT MORE CONNECTION.</span><h2>Make every next step<br /><em>feel like the right one.</em></h2></div><div class="journey-cards"><article><span class="journey-icon"><UsersRound :size="21" /></span><small>01 / KNOW YOUR PEOPLE</small><h3>Start with a conversation.</h3><p>Give every client a thoughtful story, with the details and history your team needs to make them feel seen.</p><span class="journey-line"><i></i><i></i><i></i></span></article><article><span class="journey-icon"><Compass :size="21" /></span><small>02 / FIND THEIR PLACE</small><h3>Make the right match.</h3><p>Keep your listings close to your leads, preferences, and active deals. The right home is easier to find together.</p><div class="journey-homes"><i></i><i></i><i></i><i></i></div></article><article id="home2-team"><span class="journey-icon"><ShieldCheck :size="21" /></span><small>03 / MOVE AS ONE</small><h3>Build trust as a team.</h3><p>Bring your brokerage together with clear ownership, useful history, and a calm view of what needs attention.</p><span class="team-dots"><i>A</i><i>M</i><i>S</i><i>+</i></span></article></div></section>

    <section class="home2-cta"><div class="cta2-orbit"></div><span class="home2-eyebrow">A GOOD PLACE TO START</span><h2>Your next great<br />connection is <em>waiting.</em></h2><p>Make the work flow. Give every relationship room to grow.</p><RouterLink to="/register" class="home2-button home2-light">Create your workspace <ArrowRight :size="17" /></RouterLink><RouterLink to="/login" class="home2-login">Already have a workspace? Sign in</RouterLink><div class="cta2-buildings"><i></i><i></i><i></i><i></i><i></i></div></section>

    <footer class="home2-footer"><RouterLink to="/" class="home2-brand"><span class="home2-mark">E<i></i></span><span><strong>EXOUSIA</strong><small>REALTY</small></span></RouterLink><span>Relationships first. Real estate, together.</span><small>© {{ year }} Exousia Realty</small></footer>
  </main>
</template>
