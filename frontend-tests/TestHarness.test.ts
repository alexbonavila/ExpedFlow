import { mount } from '@vue/test-utils'
import { expect, test } from 'vitest'
import TestHarness from '../resources/js/testing/TestHarness.vue'

test('rejects whitespace and shows a trimmed label after correction', async () => {
    const page = mount(TestHarness)
    const label = page.get('input')
    const form = page.get('form')

    await label.setValue('   ')
    await form.trigger('submit')
    expect(page.get('[role="alert"]').text()).toBe('Enter a test label.')
    expect(label.attributes('aria-invalid')).toBe('true')
    expect(page.get('[role="status"]').text()).toBe('')

    await label.setValue('  Browser fixture  ')
    await form.trigger('submit')
    expect(page.find('[role="alert"]').exists()).toBe(false)
    expect(label.attributes('aria-invalid')).toBeUndefined()
    expect(page.get('[role="status"]').text()).toBe(
        'Submitted: Browser fixture',
    )
})
