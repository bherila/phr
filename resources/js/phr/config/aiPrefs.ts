import { z } from 'zod'

export const AiProviderSchema = z.enum(['gemini', 'anthropic', 'bedrock'])
export type AiProvider = z.infer<typeof AiProviderSchema>

const usageSchema = z.object({
  input_tokens: z.number().int().nonnegative(),
  output_tokens: z.number().int().nonnegative(),
})

export const AiConfigurationSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  provider: AiProviderSchema,
  model: z.string(),
  masked_key: z.string(),
  has_api_key: z.boolean(),
  has_session_token: z.boolean(),
  region: z.string().nullable(),
  is_active: z.boolean(),
  is_expired: z.boolean(),
  expires_at: z.string().nullable(),
  has_invalid_api_key: z.boolean(),
  api_key_invalid_at: z.string().nullable(),
  created_at: z.string().nullable(),
  usage: z.object({
    this_month: usageSchema,
    total: usageSchema,
  }),
  available_models: z.array(z.string()).nullable().optional(),
})

export type AiConfiguration = z.infer<typeof AiConfigurationSchema>

export const AiConfigurationListSchema = z.array(AiConfigurationSchema)

export const AiModelsResponseSchema = z.object({
  models: z.array(z.string()),
})

export const AiDeleteResponseSchema = z.object({
  success: z.literal(true),
})

export const AI_PROVIDER_LABELS: Record<AiProvider, string> = {
  gemini: 'Google Gemini',
  anthropic: 'Anthropic',
  bedrock: 'Amazon Bedrock',
}
