import { expect, test } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'

test('loads the compiled harness and submits through the keyboard', async ({
    page,
}) => {
    const navigation = await page.goto('/_phase-b')
    expect(navigation?.status()).toBe(200)
    await expect(page).toHaveTitle('ExpedFlow test harness')
    const label = page.getByRole('textbox', { name: 'Test label' })

    await page.keyboard.press('Tab')
    await expect(label).toBeFocused()
    await page.keyboard.type('   ')
    await page.keyboard.press('Enter')
    await expect(page.getByRole('alert')).toHaveText('Enter a test label.')

    await label.fill('Browser fixture')
    await page.keyboard.press('Tab')
    await expect(page.getByRole('button', { name: 'Submit' })).toBeFocused()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('status')).toHaveText(
        'Submitted: Browser fixture',
    )
})

test('the harness has no axe violations in its error state @a11y', async ({
    page,
}) => {
    await page.goto('/_phase-b')
    await page.getByRole('textbox', { name: 'Test label' }).fill(' ')
    await page.getByRole('button', { name: 'Submit' }).click()
    await expect(page.getByRole('alert')).toBeVisible()

    const result = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze()
    expect(result.violations).toEqual([])
})
