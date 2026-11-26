<script setup lang="ts">
import { ref } from 'vue'

const label = ref('')
const confirmation = ref('')
const error = ref(false)

function submit(): void {
    const value = label.value.trim()
    error.value = value.length === 0
    confirmation.value = error.value ? '' : `Submitted: ${value}`
}
</script>

<template>
    <h1>ExpedFlow test harness</h1>
    <form @submit.prevent="submit">
        <label for="fixture-label">Test label</label>
        <input
            id="fixture-label"
            v-model="label"
            :aria-invalid="error || undefined"
            :aria-describedby="error ? 'fixture-error' : undefined"
            required
        />
        <button type="submit">Submit</button>
        <p v-if="error" id="fixture-error" role="alert">Enter a test label.</p>
        <p role="status">{{ confirmation }}</p>
    </form>
</template>
